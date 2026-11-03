<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DuelGame\GameKind;
use DuelGame\Player;

final class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;

    public function test(string $name, callable $fn): void
    {
        try {
            $fn();
            $this->passed++;
            echo "  \u{2713} {$name}\n";
        } catch (\Throwable $e) {
            $this->failed++;
            echo "  \u{2717} {$name}\n";
            echo "      {$e->getMessage()}\n";
        }
    }

    public function summary(): int
    {
        echo "\n{$this->passed} passed, {$this->failed} failed\n";

        return $this->failed > 0 ? 1 : 0;
    }
}

function assert_true(bool $condition, string $message = 'expected true'): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            $message !== ''
                ? $message
                : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new \RuntimeException($message !== '' ? $message : "expected to find '{$needle}'");
    }
}

/**
 * A Player paired with an in-memory capture of everything sent to it.
 */
final class TestClient
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    public Player $player;

    public function __construct(int $id, string $name)
    {
        $this->player = new Player(
            id: $id,
            name: $name,
            send: function (string $message): void {
                $this->messages[] = json_decode($message, true);
            },
            close: function (int $code, string $reason): void {
            },
        );
    }

    /** @return array<string, mixed>|null */
    public function last(): ?array
    {
        return $this->messages[count($this->messages) - 1] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function ofType(string $type): array
    {
        return array_values(array_filter(
            $this->messages,
            fn (array $m) => ($m['type'] ?? null) === $type,
        ));
    }

    public function flush(): void
    {
        $this->messages = [];
    }
}

/**
 * Minimal GameKind used to exercise the transport layer in tests.
 */
final class StubGame implements GameKind
{
    private bool $over = false;

    public function id(): string
    {
        return 'stub';
    }

    public function displayName(): string
    {
        return 'Stub Game';
    }

    public function handleMove(string $role, array $move): array
    {
        if (($move['action'] ?? '') === 'finish') {
            $this->over = true;
        }

        return [['type' => 'echo', 'role' => $role, 'move' => $move]];
    }

    public function needsResolution(): bool
    {
        return false;
    }

    public function resolve(): array
    {
        return [];
    }

    public function stateFor(string $role): array
    {
        return ['game' => 'stub', 'role' => $role, 'over' => $this->over];
    }

    public function isOver(): bool
    {
        return $this->over;
    }

    public function result(): ?array
    {
        if (!$this->over) {
            return null;
        }

        return ['winner' => 'player1', 'scores' => ['player1' => 1, 'player2' => 0]];
    }
}
