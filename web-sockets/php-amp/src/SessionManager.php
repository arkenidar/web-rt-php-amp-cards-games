<?php

declare(strict_types=1);

namespace DuelGame;

/**
 * Generates unique duel session codes and tracks the active sessions.
 */
final class SessionManager
{
    private int $counter = 0;

    /** @var array<string, DuelSession> */
    private array $sessions = [];

    public function nextCode(): string
    {
        return 'S' . str_pad((string) (++$this->counter), 4, '0', STR_PAD_LEFT);
    }

    public function add(DuelSession $session): void
    {
        $this->sessions[$session->code] = $session;
    }

    public function remove(DuelSession $session): void
    {
        unset($this->sessions[$session->code]);
    }

    public function count(): int
    {
        return count($this->sessions);
    }
}
