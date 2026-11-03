<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Amp\TimeoutCancellation;
use Amp\Websocket\Client\WebsocketConnection;
use function Amp\Websocket\Client\connect;

/**
 * Wraps a websocket client connection with a small read buffer so messages can
 * be consumed in a controlled order during the test.
 */
final class Conn
{
    public function __construct(public readonly WebsocketConnection $ws)
    {
    }

    /** @return array<string, mixed> */
    public function next(float $timeout = 10.0): array
    {
        $message = $this->ws->receive(new TimeoutCancellation($timeout));
        if ($message === null) {
            throw new \RuntimeException('connection closed unexpectedly');
        }

        return json_decode($message->buffer(), true);
    }

    /** @return array<string, mixed> */
    public function nextType(string $type, float $timeout = 10.0): array
    {
        while (true) {
            $message = $this->next($timeout);
            if (($message['type'] ?? null) === $type) {
                return $message;
            }
        }
    }
}

function waitForServer(string $host, int $port, float $timeout = 10.0): void
{
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.2);
        if ($fp !== false) {
            fclose($fp);

            return;
        }
        usleep(100000);
    }

    throw new \RuntimeException("server did not start on {$host}:{$port}");
}

function spawnServer(int $port): int
{
    $log = tempnam(sys_get_temp_dir(), 'phpamp_server_');
    $cmd = sprintf(
        'PORT=%d RESOLVE_DELAY_SECONDS=0.05 %s %s > %s 2>&1 & echo $!',
        $port,
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__DIR__ . '/../server.php'),
        escapeshellarg($log)
    );
    $pid = (int) trim(shell_exec($cmd));

    if ($pid <= 0) {
        throw new \RuntimeException('failed to start server');
    }

    return $pid;
}

function killServer(int $pid): void
{
    if ($pid > 0) {
        exec('kill ' . (int) $pid . ' 2>/dev/null');
        usleep(200000);
    }
}

/**
 * Reads one message from each connection in lockstep until a state message
 * satisfies $pred. Non-state messages are collected into $events.
 *
 * @return array<string, mixed> the matching state
 */
function readBothUntil(Conn $a, Conn $b, callable $pred, array &$events): array
{
    while (true) {
        $ma = $a->next();
        $mb = $b->next();

        foreach ([$ma, $mb] as $m) {
            if ($m['type'] === 'state') {
                if ($pred($m['state'])) {
                    return $m['state'];
                }
            } else {
                $events[] = $m;
            }
        }
    }
}

/** @return list<int> */
function faceDownPositions(array $state): array
{
    $positions = [];
    foreach ($state['board'] as $card) {
        if (!$card['matched'] && !$card['faceUp']) {
            $positions[] = $card['position'];
        }
    }

    return $positions;
}

/**
 * Accumulate revealed values across turns. Unlike a per-state snapshot, this
 * remembers a card's value even after it flips back face-down.
 *
 * @param array<int, int> $seen position => value
 */
function recordSeen(array $state, array &$seen): void
{
    foreach ($state['board'] as $card) {
        if ($card['value'] !== null) {
            $seen[$card['position']] = $card['value'];
        }
    }
}

/**
 * Group known values for the currently face-down (unmatched) cards.
 *
 * @param array<int, int> $seen position => value
 * @return array<int, list<int>> value => face-down positions
 */
function knownPairs(array $state, array $seen): array
{
    $faceDownSet = array_flip(faceDownPositions($state));
    $byValue = [];
    foreach ($seen as $position => $value) {
        if (isset($faceDownSet[$position])) {
            $byValue[$value][] = $position;
        }
    }

    return $byValue;
}

function cardValue(array $state, int $position): ?int
{
    foreach ($state['board'] as $card) {
        if ($card['position'] === $position) {
            return $card['value'];
        }
    }

    return null;
}

/**
 * @param array<int, int> $seen position => value
 */
function chooseFirst(array $state, array $seen): int
{
    $faceDown = faceDownPositions($state);

    // 1. complete a known pair
    foreach (knownPairs($state, $seen) as $positions) {
        if (count($positions) >= 2) {
            return $positions[0];
        }
    }

    // 2. reveal an unknown card to gain information
    foreach ($faceDown as $p) {
        if (!isset($seen[$p])) {
            return $p;
        }
    }

    return $faceDown[0];
}

/**
 * @param array<int, int> $seen position => value
 */
function chooseSecond(array $state, array $seen, int $first, ?int $firstValue): int
{
    $faceDown = faceDownPositions($state);
    $faceDownSet = array_flip($faceDown);

    // 1. flip the known counterpart of the first card (a guaranteed match)
    if ($firstValue !== null) {
        foreach ($seen as $position => $value) {
            if ($position !== $first && $value === $firstValue && isset($faceDownSet[$position])) {
                return $position;
            }
        }
    }

    // 2. otherwise reveal an unknown card to gain information
    foreach ($faceDown as $p) {
        if ($p !== $first && !isset($seen[$p])) {
            return $p;
        }
    }

    foreach ($faceDown as $p) {
        if ($p !== $first) {
            return $p;
        }
    }

    throw new \RuntimeException('no second card available');
}

/**
 * Drive a full Memory game to completion using both players.
 *
 * @return array{state: array<string, mixed>, game_over: ?array<string, mixed>, events: list<array<string, mixed>>}
 */
function solveMemory(Conn $alice, Conn $bob, array $initialState): array
{
    $state = $initialState;
    $seen = [];
    $events = [];
    recordSeen($state, $seen);

    $guard = 0;
    while (true) {
        if ($state['phase'] === 'finished') {
            break;
        }

        $conn = $state['turn'] === 'player1' ? $alice : $bob;

        $first = chooseFirst($state, $seen);
        $conn->ws->sendText(json_encode(['type' => 'game_move', 'move' => ['action' => 'flip', 'position' => $first]]));
        $state = readBothUntil($alice, $bob, fn (array $s) => count($s['flipped']) === 1, $events);
        recordSeen($state, $seen);

        $firstValue = cardValue($state, $first);
        $second = chooseSecond($state, $seen, $first, $firstValue);
        $conn->ws->sendText(json_encode(['type' => 'game_move', 'move' => ['action' => 'flip', 'position' => $second]]));
        $state = readBothUntil($alice, $bob, fn (array $s) => $s['flipped'] === [], $events);
        recordSeen($state, $seen);

        if (++$guard > 1000) {
            throw new \RuntimeException('solver did not terminate');
        }
    }

    $gameOver = null;
    foreach ([$alice, $bob] as $conn) {
        $message = $conn->nextType('game_over');
        if ($gameOver === null) {
            $gameOver = $message;
        }
    }

    return ['state' => $state, 'game_over' => $gameOver, 'events' => $events];
}

$runner = new TestRunner();

$runner->test('full lobby -> memory duel -> result integration', function () {
    $port = 8187;
    $pid = spawnServer($port);

    try {
        waitForServer('127.0.0.1', $port);

        $alice = new Conn(connect("ws://127.0.0.1:{$port}/ws?name=Alice"));
        $bob = new Conn(connect("ws://127.0.0.1:{$port}/ws?name=Bob"));

        $welcomeA = $alice->nextType('welcome');
        $welcomeB = $bob->nextType('welcome');
        assert_same('Alice', $welcomeA['name']);
        assert_same('Bob', $welcomeB['name']);
        assert_true(in_array('memory', $welcomeA['games'], true), 'memory game is advertised');

        // lobby chat round-trip
        $alice->ws->sendText(json_encode(['type' => 'chat', 'text' => 'hi bob']));
        $chat = $bob->nextType('chat');
        assert_same('hi bob', $chat['text']);

        // invite + accept
        $alice->ws->sendText(json_encode(['type' => 'invite', 'opponent' => 'Bob', 'game' => 'memory']));
        $invite = $bob->nextType('invite');
        assert_same('memory', $invite['game']);
        assert_same('Alice', $invite['from']);

        $bob->ws->sendText(json_encode(['type' => 'accept_invite', 'from' => 'Alice']));
        $startA = $alice->nextType('duel_start');
        $startB = $bob->nextType('duel_start');
        assert_same('player1', $startA['role']);
        assert_same('player2', $startB['role']);

        $state = $alice->nextType('state')['state'];
        $bob->nextType('state');
        assert_same('memory', $state['game']);
        assert_same(16, count($state['board']));
        foreach ($state['board'] as $card) {
            assert_same(null, $card['value'], 'face-down values are hidden over the wire');
        }

        // turn enforcement over the wire
        $bob->ws->sendText(json_encode(['type' => 'game_move', 'move' => ['action' => 'flip', 'position' => 0]]));
        $error = $bob->nextType('error');
        assert_contains('not your turn', $error['message']);

        // play the full game
        $outcome = solveMemory($alice, $bob, $state);
        assert_same('finished', $outcome['state']['phase'], 'game reached finished state');
        assert_true($outcome['game_over'] !== null, 'game_over event received');

        $scores = $outcome['game_over']['scores'];
        assert_same(8, $scores['player1'] + $scores['player2'], 'all 8 pairs were matched');

        // result is published to lobby chat history
        $watcher = new Conn(connect("ws://127.0.0.1:{$port}/ws?name=Watcher"));
        $watcher->nextType('welcome');
        $history = $watcher->nextType('lobby_history');
        $results = array_filter(
            $history['messages'] ?? [],
            fn (array $m) => ($m['kind'] ?? null) === 'result'
        );
        assert_true(count($results) > 0, 'result published to lobby chat');

        // leave the game and return to the lobby
        $alice->ws->sendText(json_encode(['type' => 'leave_game']));
        $alice->nextType('duel_ended');
        $bob->nextType('duel_ended');

        $alice->ws->close();
        $bob->ws->close();
        $watcher->ws->close();
    } finally {
        killServer($pid);
    }
});

exit($runner->summary());
