<?php

declare(strict_types=1);

namespace DuelGame;

/**
 * Contract implemented by every playable game kind (e.g. Memory, Battle Cards).
 *
 * A GameKind is pure game logic: it knows nothing about WebSockets, connections
 * or the lobby. It receives moves as plain arrays and returns events as plain
 * arrays which the transport layer (DuelSession) broadcasts to the players.
 *
 * Event shape:
 *   ['type' => 'state']                  // DuelSession fills in stateFor() per role
 *   ['type' => 'match', ...]             // broadcast to both players
 *   ['type' => 'error', 'to' => ['player1'], ...]  // optional "to" limits delivery
 */
interface GameKind
{
    /** Stable machine-readable id, e.g. "memory". */
    public function id(): string;

    /** Human-readable name shown in the UI, e.g. "Memory". */
    public function displayName(): string;

    /**
     * Apply a move from the given player role ("player1" | "player2").
     *
     * @param array<string, mixed> $move
     * @return list<array<string, mixed>> events to broadcast
     */
    public function handleMove(string $role, array $move): array;

    /**
     * Whether a delayed resolution step is pending. When true, the transport
     * layer waits resolveDelaySeconds before calling resolve().
     */
    public function needsResolution(): bool;

    /**
     * Perform the pending delayed resolution (e.g. flipping a mismatched memory
     * pair back over and passing the turn).
     *
     * @return list<array<string, mixed>> events to broadcast
     */
    public function resolve(): array;

    /**
     * Per-player state snapshot. Masking (e.g. hiding an opponent's hand) is
     * done here, which is why the role is passed in.
     *
     * @return array<string, mixed>
     */
    public function stateFor(string $role): array;

    /** True once the game has reached a terminal state. */
    public function isOver(): bool;

    /**
     * Final result, or null while the game is not over.
     *
     * @return array{winner: ?string, scores: array<string, int>}|null
     */
    public function result(): ?array;
}
