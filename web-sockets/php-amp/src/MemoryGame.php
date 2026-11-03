<?php

declare(strict_types=1);

namespace DuelGame;

/**
 * Game kind 1: Memory (concentration).
 *
 * Flip two cards per turn. A matching pair scores a point and keeps the turn;
 * a non-matching pair is revealed briefly (delayed resolution handled by the
 * transport layer) then flipped back and the turn passes.
 */
final class MemoryGame implements GameKind
{
    private const PHASE_AWAITING_FIRST = 'awaiting_first';
    private const PHASE_AWAITING_SECOND = 'awaiting_second';
    private const PHASE_RESOLVING = 'resolving';
    private const PHASE_FINISHED = 'finished';

    private int $cols;
    private int $rows;

    /** @var list<int> position => pair value */
    private array $values = [];

    /** @var array<int, array{faceUp: bool, matched: bool}> position => state */
    private array $cards = [];

    /** @var list<int> positions currently face-up (0, 1, or 2) */
    private array $flipped = [];

    /** @var array{player1: int, player2: int} */
    private array $scores = ['player1' => 0, 'player2' => 0];

    private string $turn = 'player1';
    private string $phase = self::PHASE_AWAITING_FIRST;
    private ?string $winner = null;
    private bool $pendingMismatch = false;

    /**
     * @param list<int>|null $deck Optional fixed deck (for deterministic tests);
     *                             otherwise a shuffled deck of `pairs` pairs is generated.
     */
    public function __construct(private readonly int $pairs = 8, ?array $deck = null)
    {
        if ($pairs < 2) {
            throw new \InvalidArgumentException('Memory requires at least 2 pairs');
        }

        $this->cols = 4;
        $this->rows = (int) ceil(($pairs * 2) / $this->cols);

        if ($deck !== null) {
            if (count($deck) !== $pairs * 2) {
                throw new \InvalidArgumentException('deck size must equal pairs * 2');
            }
            $this->values = array_values($deck);
        } else {
            $values = [];
            for ($v = 0; $v < $pairs; $v++) {
                $values[] = $v;
                $values[] = $v;
            }
            shuffle($values);
            $this->values = $values;
        }

        foreach ($this->values as $position => $value) {
            $this->cards[$position] = ['faceUp' => false, 'matched' => false];
        }
    }

    public function id(): string
    {
        return 'memory';
    }

    public function displayName(): string
    {
        return 'Memory';
    }

    public function handleMove(string $role, array $move): array
    {
        if ($this->phase === self::PHASE_FINISHED) {
            return [['type' => 'error', 'message' => 'game is over', 'to' => [$role]]];
        }

        if ($role !== $this->turn) {
            return [['type' => 'error', 'message' => 'not your turn', 'to' => [$role]]];
        }

        if ($this->phase === self::PHASE_RESOLVING) {
            return [['type' => 'error', 'message' => 'please wait', 'to' => [$role]]];
        }

        if (($move['action'] ?? '') !== 'flip') {
            return [['type' => 'error', 'message' => 'unknown action', 'to' => [$role]]];
        }

        $position = (int) ($move['position'] ?? -1);

        if (!isset($this->cards[$position])) {
            return [['type' => 'error', 'message' => 'invalid position', 'to' => [$role]]];
        }

        if ($this->cards[$position]['matched'] || $this->cards[$position]['faceUp']) {
            return [['type' => 'error', 'message' => 'card already revealed', 'to' => [$role]]];
        }

        $this->cards[$position]['faceUp'] = true;
        $this->flipped[] = $position;

        $action = ['type' => 'action', 'role' => $role, 'action' => 'flip', 'position' => $position];

        if (count($this->flipped) === 1) {
            $this->phase = self::PHASE_AWAITING_SECOND;

            return [$action, ['type' => 'state']];
        }

        [$a, $b] = $this->flipped;

        if ($this->values[$a] === $this->values[$b]) {
            $this->cards[$a]['matched'] = true;
            $this->cards[$b]['matched'] = true;
            $this->cards[$a]['faceUp'] = false;
            $this->cards[$b]['faceUp'] = false;
            $this->scores[$role]++;
            $this->flipped = [];
            $this->phase = self::PHASE_AWAITING_FIRST;

            if ($this->allMatched()) {
                $this->finish();
            }

            return [
                $action,
                ['type' => 'match', 'player' => $role, 'positions' => [$a, $b]],
                ['type' => 'state'],
            ];
        }

        $this->phase = self::PHASE_RESOLVING;
        $this->pendingMismatch = true;

        return [$action, ['type' => 'state']];
    }

    public function needsResolution(): bool
    {
        return $this->pendingMismatch;
    }

    public function resolve(): array
    {
        if (!$this->pendingMismatch) {
            return [];
        }

        [$a, $b] = $this->flipped;
        $this->cards[$a]['faceUp'] = false;
        $this->cards[$b]['faceUp'] = false;
        $this->flipped = [];
        $this->pendingMismatch = false;
        $this->phase = self::PHASE_AWAITING_FIRST;
        $this->turn = $this->turn === 'player1' ? 'player2' : 'player1';

        return [
            ['type' => 'mismatch', 'positions' => [$a, $b]],
            ['type' => 'state'],
        ];
    }

    public function stateFor(string $role): array
    {
        $board = [];
        foreach ($this->values as $position => $value) {
            $card = $this->cards[$position];
            $board[] = [
                'position' => $position,
                'faceUp' => $card['faceUp'],
                'matched' => $card['matched'],
                'value' => ($card['faceUp'] || $card['matched']) ? $value : null,
            ];
        }

        return [
            'game' => $this->id(),
            'display_name' => $this->displayName(),
            'cols' => $this->cols,
            'rows' => $this->rows,
            'phase' => $this->phase,
            'turn' => $this->turn,
            'flipped' => $this->flipped,
            'scores' => $this->scores,
            'winner' => $this->winner,
            'board' => $board,
        ];
    }

    public function isOver(): bool
    {
        return $this->phase === self::PHASE_FINISHED;
    }

    public function result(): ?array
    {
        if (!$this->isOver()) {
            return null;
        }

        return ['winner' => $this->winner, 'scores' => $this->scores];
    }

    private function allMatched(): bool
    {
        foreach ($this->cards as $card) {
            if (!$card['matched']) {
                return false;
            }
        }

        return true;
    }

    private function finish(): void
    {
        $this->phase = self::PHASE_FINISHED;
        $this->winner = match (true) {
            $this->scores['player1'] > $this->scores['player2'] => 'player1',
            $this->scores['player2'] > $this->scores['player1'] => 'player2',
            default => null,
        };
    }
}
