<?php

namespace Spark\PhpSocket\Commands;

use Spark\PhpSocket\Server\WebSocketServer;
use Throwable;

/**
 * Class StartSocketServerCommand
 *
 * This command starts the WebSocket server.
 * It should be run from the command line interface (CLI).
 * 
 * @package Spark\PhpSocket\Commands
 */
class StartSocketServerCommand
{
    /**
     * Execute the command to start the WebSocket server.
     *
     * @param WebSocketServer $server The WebSocket server instance.
     * @return void
     */
    public function __invoke(WebSocketServer $server)
    {
        if (php_sapi_name() === 'cli') {
            try {
                $server->start();
            } catch (Throwable $e) {
                echo "❌ Server error: " . $e->getMessage();
            }
        } else {
            echo "❌ This script must be run from command line (CLI).\n"
                . "Please use the terminal to start the WebSocket server.";
        }
    }
}