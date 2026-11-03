<?php

declare(strict_types=1);

namespace DuelGame;

/**
 * Maps game-kind ids to factories. Lets the lobby create any registered game
 * kind by id without knowing its concrete class.
 */
final class GameRegistry
{
    /** @var array<string, \Closure(): GameKind> */
    private array $factories = [];

    /** @param \Closure(): GameKind $factory */
    public function register(string $id, \Closure $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->factories);
    }

    public function create(string $id): GameKind
    {
        if (!isset($this->factories[$id])) {
            throw new \InvalidArgumentException("Unknown game kind: {$id}");
        }

        return ($this->factories[$id])();
    }
}
