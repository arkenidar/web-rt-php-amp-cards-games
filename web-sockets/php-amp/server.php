<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Socket;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use DuelGame\GameClientHandler;
use DuelGame\GameRegistry;
use DuelGame\Lobby;
use DuelGame\SessionManager;
use Psr\Log\NullLogger;
use function Amp\trapSignal;

$host = getenv('HOST') ?: '127.0.0.1';
$port = (int) (getenv('PORT') ?: 8080);
$resolveDelay = (float) (getenv('RESOLVE_DELAY_SECONDS') ?: 1.0);

$logger = new NullLogger();

$server = SocketHttpServer::createForDirectAccess($logger);
$server->expose(new Socket\InternetAddress($host, $port));

$errorHandler = new DefaultErrorHandler();

$games = new GameRegistry();
// Game kinds are registered here (MemoryGame in a later step).

$lobby = new Lobby($games, new SessionManager(), $resolveDelay);

$websocket = new Websocket($server, $logger, new Rfc6455Acceptor(), new GameClientHandler($lobby));

$router = new Router($server, $logger, $errorHandler);
$router->addRoute('GET', '/ws', $websocket);
$router->setFallback(new DocumentRoot($server, $errorHandler, __DIR__ . '/public'));

$server->start($router, $errorHandler);

echo "WebSocket endpoint: ws://{$host}:{$port}/ws\n";
echo "Static client:      http://{$host}:{$port}/\n";

$signal = trapSignal([SIGINT, SIGTERM]);
echo "Received signal {$signal}, stopping...\n";
$server->stop();
