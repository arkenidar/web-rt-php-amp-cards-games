# WebSocket Card Game Platform (PHP + Amp)

A multiplayer real-time card game platform built with the
[Amp](https://amphp.org/) WebSocket server. It provides a shared **lobby** where
players chat and invite each other into **2-player duels**, with pluggable game
kinds behind a common interface.

This directory implements the `php-amp` variant of the `web-rt-cards-game`
project.

## Requirements

- PHP 8.1+ (tested on 8.5)
- Composer (a local `composer.phar` is used here)

## Install

```bash
# with a global composer
composer install

# or with the local phar
php composer.phar install
```

## Run

```bash
php server.php
```

Then open <http://127.0.0.1:8080/> (static client) and connect to the WebSocket
endpoint at `ws://127.0.0.1:8080/ws?name=YourName`.

Configuration (environment variables):

| Variable               | Default      | Description                                      |
|------------------------|--------------|--------------------------------------------------|
| `HOST`                 | `127.0.0.1`  | Address to bind                                  |
| `PORT`                 | `8080`       | Port to bind                                     |
| `RESOLVE_DELAY_SECONDS`| `1.0`        | Delay before applying a game's delayed resolution|

## Architecture

```
server.php                 Bootstrap: HTTP server + Router + Websocket + static files
src/
  GameKind.php             Interface every game kind implements (pure game logic)
  GameRegistry.php         Maps game-kind id -> factory
  MemoryGame.php           Game kind 1: Memory (concentration)
  Player.php               Application wrapper around a WebSocket connection
  Lobby.php                Roster, chat, invites, session lifecycle, results
  DuelSession.php          Generic 2-player session: routes moves/events
  SessionManager.php       Session codes + active-session tracking
  GameClientHandler.php    Bridges raw WebSocket connections into the Lobby
public/                    Static client
test/                      Tests
```

### Design principles

- **Game-agnostic transport.** `Lobby` / `DuelSession` / `GameClientHandler`
  never interpret game rules. A `GameKind` receives moves as plain arrays and
  returns events as plain arrays; the transport only routes and broadcasts them.
- **Pure game logic.** `GameKind` implementations have no Amp/WebSocket
  dependency and are trivially unit-testable.
- **One connection per player.** A single WebSocket connection carries both
  lobby intents (chat/invite) and duel intents (game moves), routed by the
  player's current location (`lobby` vs `in_game`).

## Message protocol (JSON over WebSocket)

### Client → server

```json
{"type":"chat","text":"hello"}
{"type":"invite","opponent":"Bob","game":"memory"}
{"type":"accept_invite","from":"Alice"}
{"type":"decline_invite","from":"Alice"}
{"type":"game_move","move":{...}}
{"type":"leave_game"}
```

### Server → client

```json
{"type":"welcome","name":"Alice","lobby_users":[...],"games":["memory"]}
{"type":"lobby_history","messages":[...]}
{"type":"user_joined","name":"Bob"}
{"type":"user_left","name":"Bob"}
{"type":"user_status","name":"Bob","status":"in_game"}
{"type":"chat","from":"Alice","text":"hi"}
{"type":"chat","from":"system","kind":"result","text":"Alice 3 - 1 Bob (Alice wins)"}
{"type":"invite_sent","to":"Bob","game":"memory"}
{"type":"invite","from":"Alice","game":"memory"}
{"type":"invite_declined","by":"Bob"}
{"type":"invite_cancelled","by":"Alice"}
{"type":"duel_start","session":"S0001","game":"memory","role":"player1","opponent":"Bob"}
{"type":"state","state":{...}}
{"type":"game_over","winner":"player1","scores":{...}}
{"type":"duel_ended","reason":"finished"}
{"type":"error","message":"..."}
```

### Invite rules

- Each player holds **at most one** outgoing invite and **one** incoming invite.
- A newer invite **supersedes** the previous one (the superseded side is
  notified via `invite_cancelled`), so receivers are never spammed.
- Invites have **no timeout** and can be left unanswered.
- Disconnecting or entering a game cancels any outstanding invites.

## Game kinds

### Memory (`memory`)

A 4×4 board of 8 hidden pairs. Players alternate flipping two cards per turn: a
match scores a point and keeps the turn; a mismatch flips back (after a short
reveal window) and passes the turn. The game ends when all pairs are matched.

Game move:

```json
{"type":"game_move","move":{"action":"flip","position":7}}
```

Game events:

```json
{"type":"match","player":"player1","positions":[7,9]}
{"type":"mismatch","positions":[7,9]}
```

The `state` payload carries `cols`, `rows`, `phase` (`awaiting_first` /
`awaiting_second` / `resolving` / `finished`), `turn`, `scores`, `flipped`, and
`board` (each cell has `position`, `faceUp`, `matched`, and `value` — `value` is
`null` while face-down).

## Adding a game kind

1. Implement `DuelGame\GameKind` (e.g. `src/BattleCardsGame.php`).
2. Register it in `server.php`:

   ```php
   $games->register('battle', fn () => new \DuelGame\BattleCardsGame());
   ```

## Testing

```bash
php test/run.php                # all unit tests (no server needed)
php test/integration_test.php   # end-to-end: lobby -> duel -> result (spawns a server)
```

Unit tests use plain closures instead of real sockets, so they run without a
server. The integration test spawns `server.php` on a temporary port, connects
real WebSocket clients, and plays a full Memory game to completion via a small
solver, asserting lobby chat, invite/accept, turn enforcement, hidden card
values, and result publication to the lobby.
