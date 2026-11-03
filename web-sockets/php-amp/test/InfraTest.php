<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use DuelGame\GameRegistry;
use DuelGame\Lobby;
use DuelGame\SessionManager;

$runner = new TestRunner();

function makeLobby(): Lobby
{
    $games = new GameRegistry();
    $games->register('stub', fn () => new StubGame());

    return new Lobby($games, new SessionManager(), 0.0);
}

$runner->test('join sends welcome with roster and games', function () {
    $lobby = makeLobby();
    $a = new TestClient(1, 'Alice');
    $lobby->join($a->player);

    $welcome = $a->last();
    assert_same('welcome', $welcome['type']);
    assert_same('Alice', $welcome['name']);
    assert_same(['Alice'], array_column($welcome['lobby_users'], 'name'));
    assert_same(['stub'], $welcome['games']);
});

$runner->test('duplicate names are made unique', function () {
    $lobby = makeLobby();
    $a = new TestClient(1, 'Alice');
    $b = new TestClient(2, 'Alice');
    $lobby->join($a->player);
    $lobby->join($b->player);

    assert_same('Alice', $a->player->name);
    assert_same('Alice-2', $b->player->name);
});

$runner->test('chat broadcasts to all lobby members', function () {
    $lobby = makeLobby();
    $a = new TestClient(1, 'Alice');
    $b = new TestClient(2, 'Bob');
    $lobby->join($a->player);
    $lobby->join($b->player);

    $a->flush();
    $b->flush();

    $lobby->chat($a->player, 'hello');

    assert_same('chat', $b->last()['type']);
    assert_same('Alice', $b->last()['from']);
    assert_same('hello', $b->last()['text']);
    assert_same('chat', $a->last()['type'], 'sender also receives the broadcast');
    assert_same('hello', $a->last()['text']);
});

$runner->test('invite creates pending invites for both players', function () {
    $lobby = makeLobby();
    $a = new TestClient(1, 'Alice');
    $b = new TestClient(2, 'Bob');
    $lobby->join($a->player);
    $lobby->join($b->player);

    $a->flush();
    $b->flush();

    $lobby->invite($a->player, 'Bob', 'stub');

    $invite = $b->last();
    assert_same('invite', $invite['type']);
    assert_same('Alice', $invite['from']);
    assert_same('stub', $invite['game']);
    assert_same('invite_sent', $a->last()['type']);
});

$runner->test('accept invite starts a duel session with correct roles', function () {
    $lobby = makeLobby();
    $a = new TestClient(1, 'Alice');
    $b = new TestClient(2, 'Bob');
    $lobby->join($a->player);
    $lobby->join($b->player);

    $lobby->invite($a->player, 'Bob', 'stub');
    $a->flush();
    $b->flush();

    $lobby->acceptInvite($b->player, 'Alice');

    $aStart = $a->ofType('duel_start')[0] ?? null;
    $bStart = $b->ofType('duel_start')[0] ?? null;

    assert_true($aStart !== null, 'inviter received duel_start');
    assert_true($bStart !== null, 'invitee received duel_start');
    assert_same('player1', $aStart['role']);
    assert_same('player2', $bStart['role']);
    assert_same('Bob', $aStart['opponent']);
    assert_same('Alice', $bStart['opponent']);
    assert_same('in_game', $a->player->status);
    assert_same('in_game', $b->player->status);

    $state = $a->ofType('state')[0] ?? null;
    assert_true($state !== null, 'initial state broadcast');
    assert_same('player1', $state['state']['role'], 'state is role-masked');
});

$runner->test('decline invite notifies the inviter', function () {
    $lobby = makeLobby();
    $a = new TestClient(1, 'Alice');
    $b = new TestClient(2, 'Bob');
    $lobby->join($a->player);
    $lobby->join($b->player);

    $lobby->invite($a->player, 'Bob', 'stub');
    $a->flush();

    $lobby->declineInvite($b->player, 'Alice');

    $declined = $a->last();
    assert_same('invite_declined', $declined['type']);
    assert_same('Bob', $declined['by']);
});

$runner->test('a newer invite supersedes the previous one', function () {
    $lobby = makeLobby();
    $a = new TestClient(1, 'Alice');
    $b = new TestClient(2, 'Bob');
    $c = new TestClient(3, 'Carol');
    $lobby->join($a->player);
    $lobby->join($b->player);
    $lobby->join($c->player);

    $lobby->invite($a->player, 'Bob', 'stub');
    $lobby->invite($c->player, 'Bob', 'stub');

    assert_same('Carol', $b->player->incomingInvite['from']->name, 'receiver has latest invite');
    assert_same(null, $a->player->outgoingInvite, 'superseded invite cleared');
    assert_same(1, count($a->ofType('invite_cancelled')), 'superseded inviter notified');
});

$runner->test('cannot invite a busy player', function () {
    $lobby = makeLobby();
    $a = new TestClient(1, 'Alice');
    $b = new TestClient(2, 'Bob');
    $c = new TestClient(3, 'Carol');
    $lobby->join($a->player);
    $lobby->join($b->player);
    $lobby->join($c->player);

    $lobby->invite($a->player, 'Bob', 'stub');
    $lobby->acceptInvite($b->player, 'Alice');

    $c->flush();
    $lobby->invite($c->player, 'Bob', 'stub');

    $error = $c->last();
    assert_same('error', $error['type']);
    assert_contains('busy', $error['message']);
});

$runner->test('game over publishes result to lobby chat history', function () {
    $lobby = makeLobby();
    $a = new TestClient(1, 'Alice');
    $b = new TestClient(2, 'Bob');
    $lobby->join($a->player);
    $lobby->join($b->player);

    $lobby->invite($a->player, 'Bob', 'stub');
    $lobby->acceptInvite($b->player, 'Alice');

    $a->flush();

    $lobby->gameMove($a->player, ['action' => 'finish']);

    $over = $a->ofType('game_over');
    assert_same(1, count($over));
    assert_same('player1', $over[0]['winner']);

    $lobby->leaveGame($a->player);

    $history = $a->ofType('lobby_history');
    assert_true($history !== [], 'returning player received lobby history');
    assert_contains('Alice 1 - 0 Bob', json_encode($history), 'result published to history');
});

$runner->test('leaving a game returns both players to the lobby', function () {
    $lobby = makeLobby();
    $a = new TestClient(1, 'Alice');
    $b = new TestClient(2, 'Bob');
    $lobby->join($a->player);
    $lobby->join($b->player);

    $lobby->invite($a->player, 'Bob', 'stub');
    $lobby->acceptInvite($b->player, 'Alice');

    $b->flush();
    $lobby->leaveGame($a->player);

    assert_same('lobby', $a->player->status);
    assert_same('lobby', $b->player->status);
    assert_same(null, $a->player->session);
    assert_same(null, $b->player->session);

    $ended = $b->ofType('duel_ended');
    assert_same(1, count($ended));
    assert_same('abandoned', $ended[0]['reason']);
});

$runner->test('disconnect while in game notifies the opponent', function () {
    $lobby = makeLobby();
    $a = new TestClient(1, 'Alice');
    $b = new TestClient(2, 'Bob');
    $lobby->join($a->player);
    $lobby->join($b->player);

    $lobby->invite($a->player, 'Bob', 'stub');
    $lobby->acceptInvite($b->player, 'Alice');

    $b->flush();
    $lobby->disconnect($a->player);

    $ended = $b->ofType('duel_ended');
    assert_same(1, count($ended));
    assert_same('opponent_left', $ended[0]['reason']);
    assert_same('lobby', $b->player->status);
});

$runner->test('inviting with an unknown game kind is rejected', function () {
    $lobby = makeLobby();
    $a = new TestClient(1, 'Alice');
    $b = new TestClient(2, 'Bob');
    $lobby->join($a->player);
    $lobby->join($b->player);

    $a->flush();
    $lobby->invite($a->player, 'Bob', 'nope');

    $error = $a->last();
    assert_same('error', $error['type']);
    assert_contains('unknown game kind', $error['message']);
});

exit($runner->summary());
