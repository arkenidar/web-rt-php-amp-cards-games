<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use DuelGame\MemoryGame;

$runner = new TestRunner();

$runner->test('board is generated with correct size and hidden values', function () {
    $game = new MemoryGame(8);
    $state = $game->stateFor('player1');

    assert_same(4, $state['cols']);
    assert_same(4, $state['rows']);
    assert_same(16, count($state['board']));
    assert_same('player1', $state['turn']);
    assert_same('awaiting_first', $state['phase']);

    foreach ($state['board'] as $card) {
        assert_same(null, $card['value'], 'face-down cards hide their value');
        assert_true(!$card['faceUp'] && !$card['matched']);
    }
});

$runner->test('flip reveals exactly one card', function () {
    $game = new MemoryGame(2, [0, 0, 1, 1]);
    $events = $game->handleMove('player1', ['action' => 'flip', 'position' => 0]);

    assert_same(['action', 'state'], array_column($events, 'type'));

    $state = $game->stateFor('player1');
    assert_true($state['board'][0]['faceUp']);
    assert_same(0, $state['board'][0]['value']);
    assert_same(null, $state['board'][1]['value'], 'other cards stay hidden');
    assert_same('awaiting_second', $state['phase']);
});

$runner->test('moving out of turn is rejected', function () {
    $game = new MemoryGame(2, [0, 0, 1, 1]);
    $events = $game->handleMove('player2', ['action' => 'flip', 'position' => 0]);

    assert_same('error', $events[0]['type']);
    assert_contains('not your turn', $events[0]['message']);
});

$runner->test('flipping the same card twice is rejected', function () {
    $game = new MemoryGame(2, [0, 0, 1, 1]);
    $game->handleMove('player1', ['action' => 'flip', 'position' => 0]);
    $events = $game->handleMove('player1', ['action' => 'flip', 'position' => 0]);

    assert_same('error', $events[0]['type']);
    assert_contains('already revealed', $events[0]['message']);
});

$runner->test('a match scores and keeps the turn', function () {
    $game = new MemoryGame(2, [0, 0, 1, 1]);
    $game->handleMove('player1', ['action' => 'flip', 'position' => 0]);
    $events = $game->handleMove('player1', ['action' => 'flip', 'position' => 1]);

    assert_same(['action', 'match', 'state'], array_column($events, 'type'));

    $state = $game->stateFor('player1');
    assert_same(1, $state['scores']['player1']);
    assert_same('player1', $state['turn'], 'match keeps the turn');
    assert_true($state['board'][0]['matched']);
    assert_true($state['board'][1]['matched']);
    assert_same(false, $game->needsResolution());
});

$runner->test('a mismatch resolves and passes the turn', function () {
    $game = new MemoryGame(2, [0, 1, 1, 0]);
    $game->handleMove('player1', ['action' => 'flip', 'position' => 0]);
    $events = $game->handleMove('player1', ['action' => 'flip', 'position' => 1]);

    assert_same(['action', 'state'], array_column($events, 'type'), 'mismatch reveals both cards');
    assert_true($game->needsResolution());

    $resolved = $game->resolve();
    assert_same(['mismatch', 'state'], array_column($resolved, 'type'));

    $state = $game->stateFor('player1');
    assert_same('player2', $state['turn'], 'mismatch passes the turn');
    assert_true(!$state['board'][0]['faceUp']);
    assert_true(!$state['board'][1]['faceUp']);
    assert_same(0, $state['scores']['player1']);
    assert_same(false, $game->needsResolution());
});

$runner->test('a full game completes with a winner', function () {
    $game = new MemoryGame(2, [0, 0, 1, 1]);

    $game->handleMove('player1', ['action' => 'flip', 'position' => 0]);
    $game->handleMove('player1', ['action' => 'flip', 'position' => 1]); // match
    $game->handleMove('player1', ['action' => 'flip', 'position' => 2]);
    $events = $game->handleMove('player1', ['action' => 'flip', 'position' => 3]); // match, game over

    assert_same('match', $events[1]['type']);
    assert_true($game->isOver());

    $result = $game->result();
    assert_same('player1', $result['winner']);
    assert_same(['player1' => 2, 'player2' => 0], $result['scores']);

    $state = $game->stateFor('player1');
    assert_same('finished', $state['phase']);
    assert_same('player1', $state['winner']);
});

$runner->test('constructor validates inputs', function () {
    try {
        new MemoryGame(1);
        assert_true(false, 'expected exception for 1 pair');
    } catch (\InvalidArgumentException $e) {
        assert_contains('at least 2', $e->getMessage());
    }

    try {
        new MemoryGame(2, [0, 0, 1]);
        assert_true(false, 'expected exception for mismatched deck');
    } catch (\InvalidArgumentException $e) {
        assert_contains('deck size', $e->getMessage());
    }
});

exit($runner->summary());
