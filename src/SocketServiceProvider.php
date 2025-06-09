<?php

namespace Spark\PhpSocket;

use Spark\Console\Commands;
use Spark\Container;
use Spark\PhpSocket\Server\WebSocketServer;

/**
 * Class SocketServiceProvider
 *
 * This service provider registers the WebSocket server and its related commands.
 * It allows the application to handle real-time communication via WebSockets.
 * It also provides commands to start, stop, and publish the JavaScript client code for the WebSocket server.
 * 
 * @package Spark\PhpSocket
 */
class SocketServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(Container $container)
    {
        $container->singleton(WebSocketServer::class, fn() => new WebSocketServer(
            config('socket.host', '0.0.0.0'),
            config('socket.port', 8080),
            config('socket.debug', true)
        ));
    }

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot(Container $container)
    {
        if ($container->has(Commands::class)) {
            $commands = $container->get(Commands::class);

            // Register the WebSocket server:start command
            $commands->addCommand('socket:start', \Spark\PhpSocket\Commands\StartSocketServerCommand::class)
                ->description('Start the WebSocket server')
                ->help('This command starts the WebSocket server to handle real-time communication.');

            // Register the WebSocket server:publish-js-client command
            $commands->addCommand('socket:publish-js-client', \Spark\PhpSocket\Commands\PublishJsClientCommand::class)
                ->description('Publish the JavaScript client code')
                ->help('This command publishes the JavaScript client code for the WebSocket server.');
        }
    }
}