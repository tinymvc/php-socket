<?php

namespace Spark\PhpSocket\Commands;

use Spark\PhpSocket\Server\WebSocketServer;

/**
 * Class PublishJsClientCommand
 *
 * This command publishes the JavaScript client code for the WebSocket server.
 * It should be run from the command line interface (CLI).
 *
 * @package Spark\PhpSocket\Commands
 */
class PublishJsClientCommand
{
    /**
     * Execute the command to publish the JavaScript client code.
     *
     * @return void
     */
    public function __invoke()
    {
        $jsClientCode = __DIR__ . '/../js/phpsocket.min.js';
        $destination = root_dir('public/assets/js/phpsocket.min.js');

        // check if the directory exists, if not create it
        $destinationDir = dirname($destination);
        if (!is_dir($destinationDir)) {
            mkdir($destinationDir, 0755, true);
        }

        if (file_exists($destination)) {
            echo "The JavaScript client code already exists at: $destination\n";
            return;
        }

        // Copy the JavaScript client code to the destination
        copy($jsClientCode, $destination);
        echo "✅ JavaScript client code published to: $destination\n";
    }
}