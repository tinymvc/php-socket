<?php

namespace Spark\PhpSocket\Server;

use RuntimeException;
use Throwable;

/**
 * WebSocketServer class provides a simple WebSocket server implementation
 * that supports basic features like connection handling, message broadcasting,
 * channel subscriptions, and heartbeat management.
 * 
 * This server is designed to be lightweight and efficient, suitable for
 * small to medium-sized applications. It handles WebSocket handshakes,
 * manages multiple client connections, and allows clients to subscribe to
 * channels for real-time message delivery.
 * 
 * It supports:
 * - WebSocket protocol version 13
 * - Connection management
 * - Message encoding/decoding
 * - Channel subscriptions
 * - Heartbeat mechanism to detect dead connections
 * - Debug mode for logging events and errors
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 * @package PhpSocket
 * @version 1.0.0
 * @license MIT
 * @link https://github.com/tinymvc/php-websocket
 * 
 * TODO: These features can be implemented in the future:
 *       - Implement authentication mechanism
 *       - Add support for binary messages
 *       - Improve error handling and logging
 *       - Optimize performance for high load scenarios
 *       - Add support for SSL/TLS connections
 *       - Implement message queue for persistent delivery
 *       - Add support for custom event handling
 *       - Implement client reconnection logic
 *       - Add support for message compression
 *       - Implement rate limiting for clients
 *       - Add support for custom headers in WebSocket requests
 */
class WebSocketServer
{
    /**
     * WebSocket protocol version 13
     * This is the version currently supported by most browsers
     * 
     * @see https://tools.ietf.org/html/rfc6455
     */
    private const WEBSOCKET_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * Heartbeat interval in seconds
     * Adjust this value based on your application's needs
     * 
     * @var int
     */
    private const HEARTBEAT_INTERVAL = 100;

    /**
     * Maximum payload size in bytes
     * This limits the size of messages that can be sent/received
     * 
     * @var int
     */
    private const MAX_PAYLOAD_SIZE = 65536;

    /**
     * Server socket resource
     * This is the main socket that listens for incoming connections
     * 
     * @var resource|null
     */
    private $serverSocket;

    /**
     * Array to hold connected clients
     * Each client is stored with its ID, socket resource, channels, and last activity timestamp
     * 
     * @var array
     */
    private $clients = [];

    /**
     * Array to hold channels and their subscribed clients
     * Each channel maps to an array of client IDs that are subscribed to it
     * 
     * @var array
     */
    private $channels = [];

    /**
     * Last heartbeat timestamp
     * This is used to track when the last heartbeat check was performed
     * 
     * @var int
     */
    private $lastHeartbeat = 0;

    /**
     * Next client ID to assign
     * This is incremented each time a new client connects
     * 
     * @var int
     */
    private int $nextClientId = 1;

    /**
     * Host for the WebSocket server
     * This is the IP address or hostname on which the server listens
     * 
     * @var string
     */
    private string $host;

    /**
     * Port for the WebSocket server
     * This is the port on which the server listens for incoming connections
     * 
     * @var int
     */
    private int $port;

    /**
     * Debug mode flag
     * When enabled, the server logs detailed information about events and errors
     * 
     * @var bool
     */
    private bool $debug;

    /**
     * Running state of the server
     * This flag indicates whether the server is currently running or not
     * 
     * @var bool
     */
    private bool $running = false;

    /**
     * Constructor to initialize the WebSocket server
     * 
     * @param string $host The host address to bind the server to (default: '0.0.0.0')
     * @param int $port The port number to listen on (default: 8080)
     * @param bool $debug Enable debug mode (default: true)
     */
    public function __construct(string $host = '0.0.0.0', int $port = 8080, bool $debug = true)
    {
        $this->host = $host;
        $this->port = $port;
        $this->debug = $debug;
    }

    /**
     * Start the WebSocket server
     * This method initializes the server socket, checks if the port is available,
     * and enters the event loop to handle incoming connections and messages.
     * 
     * @throws RuntimeException If the port is already in use or if socket creation fails
     */
    public function start(): void
    {
        // Check if port is available
        $this->checkPort();

        $this->createServerSocket();
        $this->running = true;
        $this->lastHeartbeat = time();

        echo "\n🚀 WebSocket Server started on ws://{$this->host}:{$this->port}\n";

        $this->log("🔧 Debug mode: " . ($this->debug ? "ON" : "OFF"));
        $this->log("📍 Server PID: " . getmypid());
        $this->log("🔍 Test connection: Open browser console and run:");
        $this->log("   new WebSocket('ws://{$this->host}:{$this->port}')");

        $this->eventLoop();
    }

    /**
     * Check if the specified port is available for binding
     * This method attempts to create a test socket to see if the port is already in use.
     * If the port is in use, it throws an exception with an appropriate error message.
     * 
     * @throws RuntimeException If the port is already in use or if binding fails
     */
    private function checkPort(): void
    {
        $testSocket = @stream_socket_server("tcp://{$this->host}:{$this->port}", $errno, $errstr);

        if (!$testSocket) {
            if ($errno === 98 || $errno === 10048) {
                throw new RuntimeException("Port {$this->port} is already in use. Please choose a different port or kill the existing process.");
            }
            throw new RuntimeException("Cannot bind to {$this->host}:{$this->port} - $errstr ($errno)");
        }

        fclose($testSocket);
        usleep(100000); // Wait 100ms for port to be released
    }

    /**
     * Create the server socket for listening to incoming WebSocket connections
     * This method sets up the socket with appropriate context options and binds it to the specified host and port.
     * It also sets the socket to non-blocking mode for efficient event handling.
     * 
     * @throws RuntimeException If socket creation fails
     */
    private function createServerSocket(): void
    {
        // Set proper context options
        $context = stream_context_create([
            'socket' => [
                'so_reuseport' => 1,
                'so_keepalive' => 1,
                'backlog' => 128,
            ]
        ]);

        $this->serverSocket = stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if (!$this->serverSocket) {
            throw new RuntimeException("Failed to create server socket: $errstr ($errno)");
        }

        stream_set_blocking($this->serverSocket, false);

        // Set socket options for better performance
        if (function_exists('socket_import_stream')) {
            $socket = socket_import_stream($this->serverSocket);
            socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
            socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
        }
    }

    /**
     * Main event loop for handling incoming connections and messages
     * This method continuously checks for new connections, processes client messages,
     * and handles heartbeats to maintain active connections.
     * 
     * It runs until the server is stopped or an error occurs.
     */
    private function eventLoop(): void
    {
        while ($this->running) {
            try {
                $this->processConnections();
                $this->handleHeartbeat();
                usleep(1000); // 1ms to prevent high CPU usage
            } catch (Throwable $e) {
                $this->log("❌ Event loop error: " . $e->getMessage());
                if (!$this->running) {
                    break;
                }
            }
        }
    }

    /**
     * Process incoming connections and client messages
     * This method checks for new connections on the server socket and handles messages from connected clients.
     * It uses `stream_select` to efficiently manage multiple sockets without blocking.
     * 
     * It accepts new connections, performs the WebSocket handshake, and processes client messages.
     * 
     * @throws RuntimeException If an error occurs while reading from the socket
     * @return void
     */
    private function processConnections(): void
    {
        $readSockets = [$this->serverSocket];

        // Add client sockets
        foreach ($this->clients as $client) {
            if (is_resource($client['socket'])) {
                $readSockets[] = $client['socket'];
            }
        }

        $writeSockets = $exceptSockets = null;
        $socketCount = @stream_select($readSockets, $writeSockets, $exceptSockets, 0, 100000);

        if ($socketCount === false || $socketCount === 0) {
            return; // No sockets ready for reading, return early
        }

        foreach ($readSockets as $socket) {
            if ($socket === $this->serverSocket) {
                $this->acceptNewConnection();
            } else {
                $this->handleClientMessage($socket);
            }
        }
    }

    /**
     * Accept a new WebSocket connection
     * This method accepts a new connection attempt, performs the WebSocket handshake,
     * and adds the client to the list of connected clients if successful.
     * 
     * It also sends a welcome message to the newly connected client.
     * 
     * @return void
     */
    private function acceptNewConnection(): void
    {
        $clientSocket = @stream_socket_accept($this->serverSocket, 0);

        if (!$clientSocket) {
            return;
        }

        $this->log("🔗 New connection attempt from " . stream_socket_get_name($clientSocket, true));

        if ($this->performHandshake($clientSocket)) {
            $clientId = $this->nextClientId++;

            $this->clients[$clientId] = [
                'id' => $clientId,
                'socket' => $clientSocket,
                'channels' => [],
                'last_ping' => time(),
                'authenticated' => true,
                'ip' => stream_socket_get_name($clientSocket, true)
            ];

            $this->log("✅ Client #{$clientId} connected successfully");

            // Send welcome message
            $this->sendToClient($clientId, [
                'event' => 'connection:established',
                'data' => ['client_id' => $clientId]
            ]);
        } else {
            $this->log("❌ Handshake failed, closing connection");
            @fclose($clientSocket);
        }
    }

    /**
     * Perform the WebSocket handshake with the client
     * This method reads the HTTP request from the client, validates it,
     * and sends the appropriate response to establish a WebSocket connection.
     * 
     * It checks for required headers, generates the Sec-WebSocket-Accept key,
     * and sets the socket to non-blocking mode after a successful handshake.
     * 
     * @param resource $clientSocket The client socket resource
     * @return bool True if handshake was successful, false otherwise
     */
    private function performHandshake($clientSocket): bool
    {
        // Read the HTTP request
        $request = '';
        $attempts = 0;

        while ($attempts < 10) { // Max 10 attempts to read header
            $chunk = @fread($clientSocket, 1024);
            if ($chunk === false) {
                $this->log("❌ Failed to read handshake data");
                return false;
            }

            $request .= $chunk;

            // Check if we have the complete header
            if (strpos($request, "\r\n\r\n") !== false) {
                break;
            }

            $attempts++;
            usleep(10000); // Wait 10ms between attempts
        }

        if (empty($request)) {
            $this->log("❌ Empty handshake request");
            return false;
        }

        $this->log("📨 Handshake request:\n" . substr($request, 0, 200) . "...\n");

        // Validate WebSocket request
        if (!$this->isValidWebSocketRequest($request)) {
            $this->log("❌ Invalid WebSocket request");
            return false;
        }

        // Extract WebSocket key
        if (!preg_match('/Sec-WebSocket-Key:\s*(.+)\r?\n/i', $request, $matches)) {
            $this->log("❌ Missing Sec-WebSocket-Key header");
            return false;
        }

        $key = trim($matches[1]);
        $acceptKey = base64_encode(hash('sha1', $key . self::WEBSOCKET_GUID, true));

        // Build response with proper headers
        $response = "HTTP/1.1 101 Switching Protocols\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept: {$acceptKey}\r\n" .
            "Sec-WebSocket-Version: 13\r\n" .
            "Server: PhpWebSocketServer/1.0\r\n\r\n";

        $written = @fwrite($clientSocket, $response);

        if ($written === false || $written !== strlen($response)) {
            $this->log("❌ Failed to send handshake response (written: $written, expected: " . strlen($response) . ")");
            return false;
        }

        // Set socket to non-blocking mode after successful handshake
        stream_set_blocking($clientSocket, false);

        $this->log("✅ Handshake completed successfully");
        return true;
    }

    /**
     * Validate the WebSocket handshake request
     * This method checks if the request is a valid WebSocket handshake by verifying
     * the HTTP method, version, and required headers.
     * 
     * @param string $request The raw HTTP request string
     * @return bool True if the request is valid, false otherwise
     */
    private function isValidWebSocketRequest(string $request): bool
    {
        $lines = explode("\n", $request);
        $firstLine = trim($lines[0] ?? '');

        // Check HTTP method and version
        if (!preg_match('/^GET .+ HTTP\/1\.1$/i', $firstLine)) {
            $this->log("❌ Invalid HTTP request line: $firstLine");
            return false;
        }

        // Check required headers
        $hasUpgrade = preg_match('/Upgrade:\s*websocket/i', $request);
        $hasConnection = preg_match('/Connection:.*Upgrade/i', $request);
        $hasKey = preg_match('/Sec-WebSocket-Key:/i', $request);
        $hasVersion = preg_match('/Sec-WebSocket-Version:\s*13/i', $request);

        if (!$hasUpgrade)
            $this->log("❌ Missing 'Upgrade: websocket' header");
        if (!$hasConnection)
            $this->log("❌ Missing 'Connection: Upgrade' header");
        if (!$hasKey)
            $this->log("❌ Missing 'Sec-WebSocket-Key' header");
        if (!$hasVersion)
            $this->log("❌ Missing or invalid 'Sec-WebSocket-Version' header");

        return $hasUpgrade && $hasConnection && $hasKey && $hasVersion;
    }

    /**
     * Handle incoming messages from clients
     * This method reads data from the client socket, decodes the WebSocket frame,
     * and processes the message based on its opcode (text, close, ping, pong).
     * 
     * It also updates the last activity timestamp for the client and handles
     * disconnects if no data is received.
     * 
     * @param resource $clientSocket The client socket resource
     * @return void
     */
    private function handleClientMessage($clientSocket): void
    {
        $clientId = $this->findClientBySocket($clientSocket);

        if ($clientId === null) {
            $this->log("❌ Unknown client socket, closing");
            @fclose($clientSocket);
            return;
        }

        $data = @fread($clientSocket, 8192);

        if ($data === false || $data === '') {
            $this->log("🔌 Client #{$clientId} disconnected (no data)");
            $this->disconnectClient($clientId);
            return;
        }

        $frame = $this->decodeFrame($data);

        if ($frame === null) {
            $this->log("⚠️ Invalid frame from client #{$clientId}");
            return;
        }

        // Update last activity
        $this->clients[$clientId]['last_ping'] = time();

        switch ($frame['opcode']) {
            case 0x1: // Text frame
                $this->log("📨 Text message from client #{$clientId}: " . substr($frame['payload'], 0, 100));
                $this->handleTextMessage($clientId, $frame['payload']);
                break;
            case 0x8: // Close frame
                $this->log("🔌 Close frame from client #{$clientId}");
                $this->disconnectClient($clientId);
                break;
            case 0x9: // Ping frame
                $this->log("🏓 Ping from client #{$clientId}");
                $this->sendPong($clientSocket, $frame['payload']);
                break;
            case 0xA: // Pong frame
                $this->log("🏓 Pong from client #{$clientId}");
                break;
        }
    }

    /**
     * Handle text messages from clients
     * This method decodes the JSON payload, checks for the event type,
     * and processes subscription, unsubscription, or triggering events.
     * 
     * It also sends a pong response for ping events and logs any errors.
     * 
     * @param int $clientId The ID of the client sending the message
     * @param string $payload The raw JSON payload from the client
     * @return void
     */
    private function handleTextMessage(int $clientId, string $payload): void
    {
        $message = json_decode($payload, true);

        if (!$message) {
            $this->log("❌ Invalid JSON from client #{$clientId}: $payload");
            return;
        }

        if (!isset($message['event'])) {
            $this->log("❌ Missing event in message from client #{$clientId}");
            return;
        }

        $this->log("🎯 Event '{$message['event']}' from client #{$clientId}");

        switch ($message['event']) {
            case 'subscribe':
                $this->subscribeToChannel($clientId, $message['channel'] ?? '');
                break;

            case 'unsubscribe':
                $this->unsubscribeFromChannel($clientId, $message['channel'] ?? '');
                break;

            case 'trigger':
                $this->triggerEvent($clientId, $message);
                break;

            case 'ping':
                $this->sendToClient($clientId, ['event' => 'pong', 'timestamp' => time()]);
                break;
        }
    }

    /**
     * Subscribe a client to a channel
     * This method adds the client to the specified channel and updates the client's channel list.
     * It also sends a confirmation message back to the client.
     * 
     * @param int $clientId The ID of the client subscribing
     * @param string $channel The name of the channel to subscribe to
     * @return void
     */
    private function subscribeToChannel(int $clientId, string $channel): void
    {
        if (empty($channel) || !isset($this->clients[$clientId])) {
            return;
        }

        $this->clients[$clientId]['channels'][$channel] = true;
        $this->channels[$channel][$clientId] = true;

        $this->log("📡 Client #{$clientId} subscribed to '{$channel}'");

        $this->sendToClient($clientId, [
            'event' => 'subscription_succeeded',
            'channel' => $channel
        ]);
    }

    /**
     * Unsubscribe a client from a channel
     * This method removes the client from the specified channel and updates the client's channel list.
     * It also logs the unsubscription event.
     * 
     * @param int $clientId The ID of the client unsubscribing
     * @param string $channel The name of the channel to unsubscribe from
     * @return void
     */
    private function unsubscribeFromChannel(int $clientId, string $channel): void
    {
        if (empty($channel) || !isset($this->clients[$clientId])) {
            return;
        }

        unset($this->clients[$clientId]['channels'][$channel]);
        unset($this->channels[$channel][$clientId]);

        if (empty($this->channels[$channel])) {
            unset($this->channels[$channel]);
        }

        $this->log("📡 Client #{$clientId} unsubscribed from '{$channel}'");
    }

    /**
     * Trigger an event for a specific client
     * This method constructs a broadcast message and sends it to all clients subscribed to the specified channel,
     * excluding the client that triggered the event.
     * 
     * @param int $clientId The ID of the client triggering the event
     * @param array $message The message data containing channel and data
     * @return void
     */
    private function triggerEvent(int $clientId, array $message): void
    {
        $channel = $message['channel'] ?? '';
        $data = $message['data'] ?? null;

        if (empty($channel)) {
            return;
        }

        $broadcastMessage = [
            'event' => 'message',
            'channel' => $channel,
            'data' => $data,
            'timestamp' => time()
        ];

        $this->broadcastToChannel($channel, $broadcastMessage, $clientId);
    }

    /**
     * Broadcast a message to all clients subscribed to a channel
     * This method sends the specified message to all clients in the given channel,
     * excluding the client that triggered the event if specified.
     * 
     * @param string $channel The name of the channel to broadcast to
     * @param array $message The message data to send
     * @param int|null $excludeClientId The ID of the client to exclude from the broadcast (optional)
     * @return void
     */
    private function broadcastToChannel(string $channel, array $message, int $excludeClientId = null): void
    {
        if (!isset($this->channels[$channel])) {
            return;
        }

        $frame = $this->encodeFrame(json_encode($message));
        $sentCount = 0;

        foreach ($this->channels[$channel] as $clientId => $value) {
            if ($clientId !== $excludeClientId && isset($this->clients[$clientId])) {
                if ($this->sendRawToClient($clientId, $frame)) {
                    $sentCount++;
                }
            }
        }

        $this->log("📤 Broadcasted to {$sentCount} clients on channel '{$channel}'");
    }

    /**
     * Send a message to a specific client
     * This method encodes the message as a WebSocket frame and sends it to the specified client.
     * It checks if the client is still connected before attempting to send the message.
     * 
     * @param int $clientId The ID of the client to send the message to
     * @param array $message The message data to send
     * @return bool True if the message was sent successfully, false otherwise
     */
    private function sendToClient(int $clientId, array $message): bool
    {
        if (!isset($this->clients[$clientId])) {
            return false;
        }

        $frame = $this->encodeFrame(json_encode($message));
        return $this->sendRawToClient($clientId, $frame);
    }

    /**
     * Send raw data to a specific client
     * This method writes the raw data to the client's socket and handles any errors that may occur.
     * If the write operation fails, it disconnects the client.
     * 
     * @param int $clientId The ID of the client to send data to
     * @param string $data The raw data to send
     * @return bool True if the data was sent successfully, false otherwise
     */
    private function sendRawToClient(int $clientId, string $data): bool
    {
        if (!isset($this->clients[$clientId])) {
            return false;
        }

        $socket = $this->clients[$clientId]['socket'];

        if (!is_resource($socket)) {
            $this->disconnectClient($clientId);
            return false;
        }

        $written = @fwrite($socket, $data);

        if ($written === false) {
            $this->log("❌ Failed to write to client #{$clientId}");
            $this->disconnectClient($clientId);
            return false;
        }

        return true;
    }

    /**
     * Send a pong response to a client
     * This method encodes the payload as a WebSocket frame with opcode 0xA (pong)
     * and sends it to the specified client socket.
     * 
     * @param resource $socket The client socket resource
     * @param string $payload The payload to include in the pong response (optional)
     * @return void
     */
    private function sendPong($socket, string $payload = ''): void
    {
        $frame = $this->encodeFrame($payload, 0xA);
        @fwrite($socket, $frame);
    }

    /**
     * Disconnect a client and clean up resources
     * This method removes the client from all channels, closes the socket,
     * and removes the client from the list of connected clients.
     * 
     * It also logs the disconnection event.
     * 
     * @param int $clientId The ID of the client to disconnect
     * @return void
     */
    private function disconnectClient(int $clientId): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $this->log("🔌 Disconnecting client #{$clientId}");

        // Remove from all channels
        foreach ($this->clients[$clientId]['channels'] as $channel => $value) {
            unset($this->channels[$channel][$clientId]);

            if (empty($this->channels[$channel])) {
                unset($this->channels[$channel]);
            }
        }

        // Close socket
        if (is_resource($this->clients[$clientId]['socket'])) {
            @fclose($this->clients[$clientId]['socket']);
        }

        unset($this->clients[$clientId]);
    }

    /**
     * Find a client by its socket resource
     * This method searches through the connected clients to find the one associated with the given socket.
     * 
     * @param resource $socket The client socket resource to search for
     * @return int|null The ID of the client if found, null otherwise
     */
    private function findClientBySocket($socket): ?int
    {
        foreach ($this->clients as $clientId => $client) {
            if ($client['socket'] === $socket) {
                return $clientId;
            }
        }
        return null;
    }

    /**
     * Handle heartbeat checks to maintain active connections
     * This method checks the last activity timestamp of each client and disconnects those
     * that have not sent a ping within the specified heartbeat interval.
     * 
     * It also logs the current number of connected clients and channels.
     * 
     * @return void
     */
    private function handleHeartbeat(): void
    {
        $now = time();

        if ($now - $this->lastHeartbeat < self::HEARTBEAT_INTERVAL) {
            return;
        }

        $this->lastHeartbeat = $now;
        $disconnectedClients = [];

        // Check for dead connections
        foreach ($this->clients as $clientId => $client) {
            if ($now - $client['last_ping'] > self::HEARTBEAT_INTERVAL * 2) {
                $disconnectedClients[] = $clientId;
            }
        }

        // Clean up dead connections
        foreach ($disconnectedClients as $clientId) {
            $this->log("💀 Client #{$clientId} timeout");
            $this->disconnectClient($clientId);
        }

        if (count($this->clients) > 0 || count($this->channels) > 0) {
            $this->log("💓 Heartbeat: " . count($this->clients) . " clients, " . count($this->channels) . " channels");
        }
    }

    /**
     * Encode a message as a WebSocket frame
     * This method constructs a WebSocket frame with the specified payload and opcode.
     * It handles different payload lengths and applies the appropriate masking if needed.
     * 
     * @param string $payload The message payload to encode
     * @param int $opcode The opcode for the frame (default: 0x1 for text)
     * @return string The encoded WebSocket frame
     */
    private function encodeFrame(string $payload, int $opcode = 0x1): string
    {
        $length = strlen($payload);
        $frame = chr(0x80 | $opcode);

        if ($length <= 125) {
            $frame .= chr($length);
        } elseif ($length <= 65535) {
            $frame .= chr(126) . pack('n', $length);
        } else {
            $frame .= chr(127) . pack('J', $length);
        }

        return $frame . $payload;
    }

    /**
     * Decode a WebSocket frame from raw data
     * This method extracts the opcode and payload from the raw WebSocket frame data.
     * It handles different payload lengths and applies masking if necessary.
     * 
     * @param string $data The raw WebSocket frame data
     * @return array|null An associative array with 'opcode' and 'payload', or null if decoding fails
     */
    private function decodeFrame(string $data): ?array
    {
        if (strlen($data) < 2) {
            return null;
        }

        $firstByte = ord($data[0]);
        $secondByte = ord($data[1]);

        $opcode = $firstByte & 0x0F;
        $masked = ($secondByte & 0x80) === 0x80;
        $length = $secondByte & 0x7F;

        $offset = 2;

        if ($length === 126) {
            if (strlen($data) < $offset + 2)
                return null;
            $length = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
        } elseif ($length === 127) {
            if (strlen($data) < $offset + 8)
                return null;
            $length = unpack('J', substr($data, $offset, 8))[1];
            $offset += 8;
        }

        if ($length > self::MAX_PAYLOAD_SIZE) {
            return null;
        }

        if ($masked) {
            if (strlen($data) < $offset + 4)
                return null;
            $mask = substr($data, $offset, 4);
            $offset += 4;
        }

        if (strlen($data) < $offset + $length) {
            return null;
        }

        $payload = substr($data, $offset, $length);

        if ($masked) {
            for ($i = 0; $i < $length; $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        }

        return [
            'opcode' => $opcode,
            'payload' => $payload
        ];
    }

    /**
     * Stop the WebSocket server
     * This method gracefully stops the server, disconnects all clients,
     * and closes the server socket.
     * 
     * It logs the shutdown event and cleans up resources.
     * 
     * @return void
     */
    public function stop(): void
    {
        $this->running = false;

        // Disconnect all clients
        foreach ($this->clients as $clientId => $client) {
            $this->disconnectClient($clientId);
        }

        // Close server socket
        if (is_resource($this->serverSocket)) {
            fclose($this->serverSocket);
        }

        echo "🛑 WebSocket Server stopped\n";
    }

    /**
     * Log a message if debug mode is enabled
     * This method outputs the message to the console if debug mode is active.
     * 
     * @param string $message The message to log
     * @return void
     */
    private function log(string $message): void
    {
        if ($this->debug) {
            echo "$message\n";
        }
    }
}