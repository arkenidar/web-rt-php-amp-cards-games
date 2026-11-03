<?php

declare(strict_types=1);

namespace DuelGame;

use function Amp\delay;

/**
 * A private 2-player game session.
 *
 * Holds the two players and a GameKind instance, and routes moves/events between
 * them. This class is game-agnostic: it never interprets game semantics.
 */
final class DuelSession
{
    /** Seconds to wait before applying a pending delayed resolution. */
    public float $resolveDelaySeconds = 1.0;

    /** @var \Closure(array<string, mixed>): void|null Called with the result when the game ends. */
    private ?\Closure $onGameOver = null;

    public function __construct(
        public readonly string $code,
        public readonly GameKind $game,
        public readonly Player $player1,
        public readonly Player $player2,
    ) {
        $this->player1->role = 'player1';
        $this->player2->role = 'player2';
        $this->player1->session = $this;
        $this->player2->session = $this;
        $this->player1->status = Player::STATUS_IN_GAME;
        $this->player2->status = Player::STATUS_IN_GAME;
    }

    /** @param \Closure(array<string, mixed>): void $onGameOver */
    public function onGameOver(\Closure $onGameOver): void
    {
        $this->onGameOver = $onGameOver;
    }

    public function opponent(Player $player): Player
    {
        return $player === $this->player1 ? $this->player2 : $this->player1;
    }

    public function broadcastState(): void
    {
        $this->broadcastEvent(['type' => 'state']);
    }

    /**
     * Broadcast an event, honoring per-role masking for "state" events and an
     * optional "to" role list for targeted events.
     *
     * @param array<string, mixed> $event
     */
    public function broadcastEvent(array $event): void
    {
        $targets = $event['to'] ?? ['player1', 'player2'];
        unset($event['to']);

        foreach ($targets as $role) {
            $message = $event;

            if ($event['type'] === 'state') {
                $message['state'] = $this->game->stateFor($role);
            }

            $this->playerByRole($role)->send($message);
        }
    }

    /** @param array<string, mixed> $move */
    public function handleMove(Player $player, array $move): void
    {
        $events = $this->game->handleMove($player->role, $move);
        foreach ($events as $event) {
            $this->broadcastEvent($event);
        }

        if ($this->game->isOver()) {
            $this->finish();
            return;
        }

        if ($this->game->needsResolution()) {
            delay($this->resolveDelaySeconds);

            foreach ($this->game->resolve() as $event) {
                $this->broadcastEvent($event);
            }

            if ($this->game->isOver()) {
                $this->finish();
            }
        }
    }

    private function playerByRole(string $role): Player
    {
        return $role === 'player1' ? $this->player1 : $this->player2;
    }

    private function finish(): void
    {
        $result = $this->game->result() ?? ['winner' => null, 'scores' => []];

        $this->broadcastEvent([
            'type' => 'game_over',
            'winner' => $result['winner'],
            'scores' => $result['scores'],
        ]);

        if ($this->onGameOver !== null) {
            ($this->onGameOver)($result);
        }
    }
}
