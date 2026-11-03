<?php

declare(strict_types=1);

namespace DuelGame;

/**
 * Application-level wrapper around a single connected WebSocket client.
 *
 * Deliberately decoupled from Amp/Websocket so the lobby & session logic can be
 * unit-tested with plain closures instead of real sockets.
 */
final class Player
{
    public const STATUS_LOBBY = 'lobby';
    public const STATUS_IN_GAME = 'in_game';

    public string $status = self::STATUS_LOBBY;

    /** @var array{from: Player, game: string}|null Invite received by this player. */
    public ?array $incomingInvite = null;

    /** @var array{to: Player, game: string}|null Invite sent by this player. */
    public ?array $outgoingInvite = null;

    public ?DuelSession $session = null;

    /** @var string|null "player1" or "player2" while inside a duel. */
    public ?string $role = null;

    /** @var \Closure(string): void */
    private \Closure $send;

    /** @var \Closure(int, string): void */
    private \Closure $close;

    /**
     * @param \Closure(string): void $send
     * @param \Closure(int, string): void $close
     */
    public function __construct(
        public readonly int $id,
        public string $name,
        \Closure $send,
        \Closure $close,
    ) {
        $this->send = $send;
        $this->close = $close;
    }

    /** @param array<string, mixed> $message */
    public function send(array $message): void
    {
        ($this->send)(json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        ($this->close)($code, $reason);
    }

    public function isInLobby(): bool
    {
        return $this->status === self::STATUS_LOBBY;
    }

    public function clearInvites(): void
    {
        $this->incomingInvite = null;
        $this->outgoingInvite = null;
    }
}
