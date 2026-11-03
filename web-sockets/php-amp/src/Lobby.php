<?php

declare(strict_types=1);

namespace DuelGame;

/**
 * The lobby: a shared space where connected players chat, invite each other to
 * duels, and are paired into 2-player game sessions.
 */
final class Lobby
{
    private const MAX_HISTORY = 100;
    private const MAX_NAME_LENGTH = 24;

    /** @var array<int, Player> keyed by player id */
    private array $players = [];

    /** @var list<array<string, mixed>> */
    private array $history = [];

    public function __construct(
        private readonly GameRegistry $games,
        private readonly SessionManager $sessions,
        private readonly float $resolveDelaySeconds = 1.0,
    ) {
    }

    public function join(Player $player): void
    {
        $player->name = $this->uniqueName($player->name);
        $this->players[$player->id] = $player;

        $player->send([
            'type' => 'welcome',
            'name' => $player->name,
            'lobby_users' => $this->roster(),
            'games' => $this->games->ids(),
        ]);

        if ($this->history !== []) {
            $player->send(['type' => 'lobby_history', 'messages' => $this->history]);
        }

        $this->broadcast(['type' => 'user_joined', 'name' => $player->name], except: $player);
    }

    /** Handle a full disconnect (socket closed). */
    public function disconnect(Player $player): void
    {
        unset($this->players[$player->id]);

        if ($player->session !== null) {
            $this->endSession($player->session, 'opponent_left');
        }

        $this->cancelInvitesInvolving($player);

        $this->broadcast(['type' => 'user_left', 'name' => $player->name]);
    }

    public function chat(Player $player, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        if (mb_strlen($text) > 1000) {
            $text = mb_substr($text, 0, 1000);
        }

        $this->pushHistory(['from' => $player->name, 'text' => $text]);
        $this->broadcast(['type' => 'chat', 'from' => $player->name, 'text' => $text]);
    }

    public function invite(Player $from, string $toName, string $gameId): void
    {
        if (!$from->isInLobby()) {
            $from->send(['type' => 'error', 'message' => 'you must be in the lobby to invite someone']);
            return;
        }

        if (!$this->games->has($gameId)) {
            $from->send(['type' => 'error', 'message' => 'unknown game kind: ' . $gameId]);
            return;
        }

        $target = $this->findByName($toName);

        if ($target === null) {
            $from->send(['type' => 'error', 'message' => 'player not found: ' . $toName]);
            return;
        }

        if ($target === $from) {
            $from->send(['type' => 'error', 'message' => 'you cannot invite yourself']);
            return;
        }

        if (!$target->isInLobby()) {
            $from->send(['type' => 'error', 'message' => $target->name . ' is busy']);
            return;
        }

        // One outstanding invite per side: supersede any previous invite.
        if ($from->outgoingInvite !== null) {
            $oldTarget = $from->outgoingInvite['to'];
            $oldTarget->incomingInvite = null;
            $oldTarget->send(['type' => 'invite_cancelled', 'by' => $from->name]);
        }

        if ($target->incomingInvite !== null && $target->incomingInvite['from'] !== $from) {
            $oldInviter = $target->incomingInvite['from'];
            $oldInviter->outgoingInvite = null;
            $oldInviter->send(['type' => 'invite_cancelled', 'to' => $target->name]);
        }

        $from->outgoingInvite = ['to' => $target, 'game' => $gameId];
        $target->incomingInvite = ['from' => $from, 'game' => $gameId];

        $from->send(['type' => 'invite_sent', 'to' => $target->name, 'game' => $gameId]);
        $target->send(['type' => 'invite', 'from' => $from->name, 'game' => $gameId]);
    }

    public function acceptInvite(Player $player, string $fromName): void
    {
        $invite = $player->incomingInvite;

        if ($invite === null) {
            $player->send(['type' => 'error', 'message' => 'no pending invite']);
            return;
        }

        $inviter = $invite['from'];

        if ($inviter->name !== $fromName) {
            $player->send(['type' => 'error', 'message' => 'no invite from ' . $fromName]);
            return;
        }

        if (!$this->isConnected($inviter) || !$inviter->isInLobby()) {
            $player->incomingInvite = null;
            $inviter->outgoingInvite = null;
            $player->send(['type' => 'error', 'message' => 'inviter is no longer available']);
            return;
        }

        $player->incomingInvite = null;
        $inviter->outgoingInvite = null;

        $game = $this->games->create($invite['game']);
        $code = $this->sessions->nextCode();
        $session = new DuelSession($code, $game, $inviter, $player);
        $session->resolveDelaySeconds = $this->resolveDelaySeconds;
        $session->onGameOver(fn (array $result) => $this->publishResult($session, $result));
        $this->sessions->add($session);

        $this->markStatus($inviter, Player::STATUS_IN_GAME);
        $this->markStatus($player, Player::STATUS_IN_GAME);

        $inviter->send([
            'type' => 'duel_start',
            'session' => $code,
            'game' => $game->id(),
            'role' => 'player1',
            'opponent' => $player->name,
        ]);
        $player->send([
            'type' => 'duel_start',
            'session' => $code,
            'game' => $game->id(),
            'role' => 'player2',
            'opponent' => $inviter->name,
        ]);

        $session->broadcastState();
    }

    public function declineInvite(Player $player, string $fromName): void
    {
        $invite = $player->incomingInvite;

        if ($invite === null || $invite['from']->name !== $fromName) {
            $player->send(['type' => 'error', 'message' => 'no invite from ' . $fromName]);
            return;
        }

        $inviter = $invite['from'];
        $player->incomingInvite = null;
        $inviter->outgoingInvite = null;

        $inviter->send(['type' => 'invite_declined', 'by' => $player->name]);
    }

    public function leaveGame(Player $player): void
    {
        if ($player->session === null) {
            $player->send(['type' => 'error', 'message' => 'you are not in a game']);
            return;
        }

        $reason = $player->session->game->isOver() ? 'finished' : 'abandoned';
        $this->endSession($player->session, $reason);
    }

    /** @param array<string, mixed> $move */
    public function gameMove(Player $player, array $move): void
    {
        if ($player->session === null) {
            $player->send(['type' => 'error', 'message' => 'you are not in a game']);
            return;
        }

        $player->session->handleMove($player, $move);
    }

    private function endSession(DuelSession $session, string $reason): void
    {
        $this->sessions->remove($session);

        foreach ([$session->player1, $session->player2] as $player) {
            $player->send(['type' => 'duel_ended', 'reason' => $reason]);
            $player->session = null;
            $player->role = null;
            $player->clearInvites();
            $this->markStatus($player, Player::STATUS_LOBBY);

            if ($this->history !== []) {
                $player->send(['type' => 'lobby_history', 'messages' => $this->history]);
            }
        }
    }

    /** @param array<string, mixed> $result */
    private function publishResult(DuelSession $session, array $result): void
    {
        $p1 = $session->player1;
        $p2 = $session->player2;
        $s1 = $result['scores']['player1'] ?? 0;
        $s2 = $result['scores']['player2'] ?? 0;
        $winner = $result['winner'];

        $text = sprintf('%s %d - %d %s', $p1->name, $s1, $s2, $p2->name);
        if ($winner === 'player1') {
            $text .= " ({$p1->name} wins)";
        } elseif ($winner === 'player2') {
            $text .= " ({$p2->name} wins)";
        } else {
            $text .= ' (tie)';
        }

        $entry = ['from' => 'system', 'kind' => 'result', 'text' => $text];
        $this->pushHistory($entry);
        $this->broadcast(['type' => 'chat', 'from' => 'system', 'kind' => 'result', 'text' => $text]);
    }

    private function markStatus(Player $player, string $status): void
    {
        $player->status = $status;
        $this->broadcast(['type' => 'user_status', 'name' => $player->name, 'status' => $status]);
    }

    private function cancelInvitesInvolving(Player $player): void
    {
        if ($player->outgoingInvite !== null) {
            $target = $player->outgoingInvite['to'];
            $target->incomingInvite = null;
            $target->send(['type' => 'invite_cancelled', 'by' => $player->name]);
        }

        if ($player->incomingInvite !== null) {
            $inviter = $player->incomingInvite['from'];
            $inviter->outgoingInvite = null;
            $inviter->send(['type' => 'invite_cancelled', 'to' => $player->name]);
        }

        $player->clearInvites();
    }

    private function findByName(string $name): ?Player
    {
        foreach ($this->players as $player) {
            if ($player->name === $name) {
                return $player;
            }
        }

        return null;
    }

    private function isConnected(Player $player): bool
    {
        return isset($this->players[$player->id]);
    }

    private function uniqueName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'Guest-' . random_int(1000, 9999);
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $name = mb_substr($name, 0, self::MAX_NAME_LENGTH);
        }

        $base = $name;
        $suffix = 2;
        while ($this->findByName($name) !== null) {
            $name = $base . '-' . $suffix++;
        }

        return $name;
    }

    /** @return list<array{name: string, status: string}> */
    private function roster(): array
    {
        $roster = [];
        foreach ($this->players as $player) {
            $roster[] = ['name' => $player->name, 'status' => $player->status];
        }

        return $roster;
    }

    /** @param array<string, mixed> $entry */
    private function pushHistory(array $entry): void
    {
        $this->history[] = $entry;
        if (count($this->history) > self::MAX_HISTORY) {
            array_shift($this->history);
        }
    }

    /** @param array<string, mixed> $message */
    private function broadcast(array $message, ?Player $except = null): void
    {
        foreach ($this->players as $player) {
            if ($player === $except || !$player->isInLobby()) {
                continue;
            }

            $player->send($message);
        }
    }
}
