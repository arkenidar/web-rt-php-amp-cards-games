<?php

declare(strict_types=1);

namespace DuelGame;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;

/**
 * Bridges raw WebSocket connections into the Lobby: creates a Player for each
 * connection, dispatches incoming JSON intents, and cleans up on disconnect.
 */
final class GameClientHandler implements WebsocketClientHandler
{
    public function __construct(private readonly Lobby $lobby)
    {
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        $name = $this->extractName($request);

        $player = new Player(
            id: $client->getId(),
            name: $name,
            send: fn (string $message) => $this->safeSend($client, $message),
            close: fn (int $code, string $reason) => $this->safeClose($client, $code, $reason),
        );

        $this->lobby->join($player);

        try {
            while ($message = $client->receive()) {
                try {
                    $payload = $message->buffer();
                } catch (\Throwable) {
                    break; // connection dropped mid-message
                }

                $this->dispatch($player, $payload);
            }
        } finally {
            $this->lobby->disconnect($player);
        }
    }

    private function dispatch(Player $player, string $payload): void
    {
        $data = json_decode($payload, true);

        if (!is_array($data) || !is_string($data['type'] ?? null)) {
            $player->send(['type' => 'error', 'message' => 'invalid message: expected a JSON object with a "type"']);
            return;
        }

        try {
            switch ($data['type']) {
                case 'chat':
                    $this->lobby->chat($player, (string) ($data['text'] ?? ''));
                    break;
                case 'invite':
                    $this->lobby->invite($player, (string) ($data['opponent'] ?? ''), (string) ($data['game'] ?? ''));
                    break;
                case 'accept_invite':
                    $this->lobby->acceptInvite($player, (string) ($data['from'] ?? ''));
                    break;
                case 'decline_invite':
                    $this->lobby->declineInvite($player, (string) ($data['from'] ?? ''));
                    break;
                case 'game_move':
                    $this->lobby->gameMove($player, is_array($data['move'] ?? null) ? $data['move'] : []);
                    break;
                case 'leave_game':
                    $this->lobby->leaveGame($player);
                    break;
                default:
                    $player->send(['type' => 'error', 'message' => 'unknown message type: ' . $data['type']]);
            }
        } catch (\Throwable $e) {
            $player->send(['type' => 'error', 'message' => 'error: ' . $e->getMessage()]);
        }
    }

    private function extractName(Request $request): string
    {
        parse_str($request->getUri()->getQuery(), $query);

        return is_string($query['name'] ?? null) ? (string) $query['name'] : '';
    }

    private function safeSend(WebsocketClient $client, string $message): void
    {
        try {
            $client->sendText($message);
        } catch (\Throwable) {
            // Connection already closed; nothing to do.
        }
    }

    private function safeClose(WebsocketClient $client, int $code, string $reason): void
    {
        try {
            $client->close($code, $reason);
        } catch (\Throwable) {
            // Already closed.
        }
    }
}
