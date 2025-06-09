/**
 * PhpSocket - A JavaScript library for real-time WebSocket communication
 * This library provides a simple interface for connecting to a WebSocket server,
 * subscribing to channels, and handling events similar to Pusher.
 * 
 * @version 1.0.0
 * @license MIT
 * Copyright (c) 2025 Shahin Mosyan
 */
class PhpSocket {
    /**
     * Create a new PhpSocket instance
     * This constructor initializes the WebSocket connection and sets up event listeners.
     * 
     * @param {string} url The WebSocket server URL
     * @param {object} options Configuration options for the socket
     */
    constructor(url, options = {}) {
        this.url = url;
        this.options = {
            reconnect: true,
            reconnectInterval: 5000,
            maxReconnectAttempts: 10,
            enableHeartbeat: true,
            heartbeatInterval: 100000,
            debug: false,
            ...options
        };

        // Connection state
        this.ws = null;
        this.state = 'disconnected'; // 'connecting', 'connected', 'disconnected', 'reconnecting'
        this.reconnectAttempts = 0;
        this.reconnectTimer = null;
        this.heartbeatTimer = null;
        this.lastHeartbeat = null;

        // Channel and event management
        this.channels = new Map(); // channel -> Channel instance
        this.globalEvents = new Map(); // event -> [callbacks]
        this.pendingSubscriptions = new Set();
        this.messageQueue = [];

        // Bind methods to preserve context
        this.connect = this.connect.bind(this);
        this.disconnect = this.disconnect.bind(this);
        this.reconnect = this.reconnect.bind(this);

        // Auto-connect
        this.connect();
    }

    /**
     * Connect to the WebSocket server
     * This method initiates the WebSocket connection and sets up event handlers.
     * 
     * @returns {boolean} True if the socket is connected, false otherwise
     */
    connect() {
        if (this.state === 'connecting' || this.state === 'connected') {
            return;
        }

        this.state = 'connecting';
        this.log('Connecting to WebSocket...');

        try {
            this.ws = new WebSocket(this.url);
            this.setupEventHandlers();
        } catch (error) {
            this.log('Connection failed:', error);
            this.handleConnectionError();
        }
    }

    /**
     * Handle connection errors
     * This method is called when the WebSocket connection fails to establish.
     * It schedules a reconnection attempt if auto-reconnect is enabled.
     */
    setupEventHandlers() {
        this.ws.onopen = () => {
            this.state = 'connected';
            this.reconnectAttempts = 0;
            this.log('✅ Connected to WebSocket');

            // Clear reconnect timer
            if (this.reconnectTimer) {
                clearTimeout(this.reconnectTimer);
                this.reconnectTimer = null;
            }

            // Start heartbeat
            this.startHeartbeat();

            // Process queued messages
            this.processMessageQueue();

            // Re-subscribe to channels
            this.resubscribeChannels();

            // Emit connection event
            this.emit('connection:established');
        };

        this.ws.onmessage = (event) => {
            try {
                const message = JSON.parse(event.data);
                this.handleMessage(message);
            } catch (error) {
                this.log('Invalid message received:', error);
            }
        };

        this.ws.onclose = (event) => {
            this.state = 'disconnected';
            this.log('🔌 WebSocket connection closed', event.code, event.reason);

            // Stop heartbeat
            this.stopHeartbeat();

            // Emit disconnection event
            this.emit('connection:closed', { code: event.code, reason: event.reason });

            // Auto-reconnect if enabled and not a clean close
            if (this.options.reconnect && event.code !== 1000) {
                this.scheduleReconnect();
            }
        };

        this.ws.onerror = (error) => {
            this.log('❌ WebSocket error:', error);
            this.emit('connection:error', error);
        };
    }

    /**
     * Handle incoming WebSocket messages
     * This method processes messages received from the WebSocket server.
     * 
     * @param {object} message The message object received from the server
     */
    handleMessage(message) {
        this.log('📨 Received:', message);

        const { event, channel, data } = message;

        switch (event) {
            case 'subscription_succeeded':
                this.handleSubscriptionSucceeded(channel);
                break;
            case 'pong':
                this.handlePong();
                break;
            case 'message':
                this.handleChannelMessage(channel, data);
                break;
            default:
                // Handle global events or channel-specific events
                if (channel && this.channels.has(channel)) {
                    this.channels.get(channel).trigger(event, data);
                } else {
                    this.emit(event, data);
                }
        }
    }

    /**
     * Handle successful channel subscription
     * This method is called when the client successfully subscribes to a channel.
     * 
     * @param {string} channelName The name of the channel that was subscribed to
     */
    handleSubscriptionSucceeded(channelName) {
        this.pendingSubscriptions.delete(channelName);
        if (this.channels.has(channelName)) {
            const channel = this.channels.get(channelName);
            channel.subscribed = true;
            channel.trigger('subscription:succeeded');
        }
        this.log(`✅ Subscribed to channel: ${channelName}`);
    }

    /**
     * Handle messages received on a specific channel
     * This method triggers the 'message' event on the channel if it exists.
     * 
     * @param {string} channelName The name of the channel that received the message
     * @param {object} data The message data
     */
    handleChannelMessage(channelName, data) {
        if (this.channels.has(channelName)) {
            this.channels.get(channelName).trigger('message', data);
        }
    }

    /**
     * Handle pong messages
     * This method is called when a pong message is received from the server.
     * It updates the last heartbeat timestamp and logs the event.
     * 
     * @param {object} message The pong message received
     * @returns {void}
     */
    handlePong() {
        this.lastHeartbeat = Date.now();
        this.log('💓 Pong received');
    }

    /**
     * Schedule a reconnection attempt
     * This method is called when the WebSocket connection is lost.
     * It schedules a reconnection attempt if auto-reconnect is enabled.
     * 
     * @returns {void}
     */
    scheduleReconnect() {
        if (this.reconnectAttempts >= this.options.maxReconnectAttempts) {
            this.log('❌ Max reconnection attempts reached');
            this.emit('connection:failed');
            return;
        }

        this.state = 'reconnecting';
        this.reconnectAttempts++;

        const delay = this.options.reconnectInterval * Math.pow(1.5, this.reconnectAttempts - 1);
        this.log(`🔄 Reconnecting in ${delay}ms (attempt ${this.reconnectAttempts})`);

        this.reconnectTimer = setTimeout(() => {
            if (this.state === 'reconnecting') {
                this.connect();
            }
        }, delay);
    }

    /**
     * Start the heartbeat mechanism
     * This method initiates the heartbeat process to keep the connection alive.
     * 
     * @returns {void}
     */
    startHeartbeat() {
        if (!this.options.enableHeartbeat) return;

        this.lastHeartbeat = Date.now();
        this.heartbeatTimer = setInterval(() => {
            if (this.state === 'connected') {
                this.send({ event: 'ping' });

                // Check if we haven't received a pong in too long
                const now = Date.now();
                if (now - this.lastHeartbeat > this.options.heartbeatInterval * 2) {
                    this.log('💀 Heartbeat timeout - closing connection');
                    this.ws.close();
                }
            }
        }, this.options.heartbeatInterval);
    }

    /**
     * Stop the heartbeat mechanism
     * This method stops the heartbeat process and clears the timer.
     * 
     * @returns {void}
     */
    stopHeartbeat() {
        if (this.heartbeatTimer) {
            clearInterval(this.heartbeatTimer);
            this.heartbeatTimer = null;
        }
    }

    /**
     * Send a message to the WebSocket server
     * This method serializes the message and sends it over the WebSocket connection.
     * If the connection is not ready, the message is queued for later delivery.
     * 
     * @param {object} message The message object to send
     */
    send(message) {
        if (this.state === 'connected' && this.ws.readyState === WebSocket.OPEN) {
            const payload = JSON.stringify(message);
            this.ws.send(payload);
            this.log('📤 Sent:', message);
        } else {
            this.messageQueue.push(message);
            this.log('📦 Queued message:', message);
        }
    }

    /**
     * Process the message queue
     * This method sends all queued messages if the WebSocket connection is ready.
     * 
     * @returns {void}
     */
    processMessageQueue() {
        while (this.messageQueue.length > 0) {
            const message = this.messageQueue.shift();
            this.send(message);
        }
    }

    /**
     * Resubscribe to all channels
     * This method iterates through all channels and resubscribes to those that are not currently subscribed.
     * It is useful after a reconnection to ensure all channels are active.
     * 
     * @returns {void}
     */
    resubscribeChannels() {
        for (const [channelName, channel] of this.channels) {
            if (!channel.subscribed) {
                this.send({ event: 'subscribe', channel: channelName });
                this.pendingSubscriptions.add(channelName);
            }
        }
    }

    /**
     * Subscribe to a channel
     * This method creates a new channel instance and sends a subscription request to the server.
     * 
     * @param {string} channelName The name of the channel to subscribe to
     * @returns {Channel} The channel instance
     */
    subscribe(channelName) {
        if (this.channels.has(channelName)) {
            return this.channels.get(channelName);
        }

        const channel = new Channel(channelName, this);
        this.channels.set(channelName, channel);

        // Send subscription request
        this.send({ event: 'subscribe', channel: channelName });
        this.pendingSubscriptions.add(channelName);

        return channel;
    }

    /**
     * Unsubscribe from a channel
     * This method removes the channel from the list of active channels and sends an unsubscribe request to the server.
     * 
     * @param {string} channelName The name of the channel to unsubscribe from
     * @returns {void}
     */
    unsubscribe(channelName) {
        if (!this.channels.has(channelName)) {
            return;
        }

        const channel = this.channels.get(channelName);
        channel.unsubscribe();
        this.channels.delete(channelName);
        this.pendingSubscriptions.delete(channelName);

        this.send({ event: 'unsubscribe', channel: channelName });
        this.log(`🚫 Unsubscribed from channel: ${channelName}`);
    }

    /**
     * Bind a global event listener
     * This method allows you to listen for events that are emitted by the socket.
     * 
     * @param {string} eventName The name of the event to listen for
     * @param {function} callback The callback function to execute when the event is triggered
     * @returns {this}
     */
    bind(eventName, callback) {
        if (!this.globalEvents.has(eventName)) {
            this.globalEvents.set(eventName, []);
        }

        this.globalEvents.get(eventName).push(callback);

        return this;
    }

    /**
     * Unbind a global event listener
     * This method allows you to stop listening for events that are emitted by the socket.
     * 
     * @param {string} eventName The name of the event to stop listening for
     * @param {function} callback The callback function to remove
     * @returns {this}
     */
    unbind(eventName, callback = null) {
        if (!this.globalEvents.has(eventName)) return this;

        if (callback) {
            const callbacks = this.globalEvents.get(eventName);
            const index = callbacks.indexOf(callback);
            if (index !== -1) {
                callbacks.splice(index, 1);
            }
        } else {
            this.globalEvents.delete(eventName);
        }
        return this;
    }

    /**
     * Emit a global event
     * This method triggers all callbacks registered for the specified event.
     * 
     * @param {string} eventName The name of the event to emit
     * @param {*} data The data to pass to the event callbacks
     */
    emit(eventName, data = null) {
        if (this.globalEvents.has(eventName)) {
            this.globalEvents.get(eventName).forEach(callback => {
                try {
                    callback(data);
                } catch (error) {
                    this.log('Error in event callback:', error);
                }
            });
        }
    }

    /**
     * Trigger an event on a specific channel
     * This method sends a trigger request to the server for the specified channel and event.
     * 
     * @param {string} channelName The name of the channel to trigger the event on
     * @param {string} eventName The name of the event to trigger
     * @param {*} data The data to send with the event
     */
    trigger(channelName, eventName, data) {
        this.send({
            event: 'trigger',
            channel: channelName,
            data: { event: eventName, data }
        });
    }

    /**
     * Disconnect from the WebSocket server
     * This method closes the WebSocket connection and clears all channels and event listeners.
     * It also stops the heartbeat and clears any pending subscriptions.
     * 
     * @returns {void}
     * @throws {Error} If the socket is already disconnected
     */
    disconnect() {
        this.options.reconnect = false; // Disable auto-reconnect
        this.state = 'disconnected';

        // Clear timers
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
        this.stopHeartbeat();

        // Clear channels
        this.channels.clear();
        this.globalEvents.clear();
        this.pendingSubscriptions.clear();
        this.messageQueue = [];

        // Close WebSocket
        if (this.ws && (this.ws.readyState === WebSocket.OPEN || this.ws.readyState === WebSocket.CONNECTING)) {
            this.ws.close(1000, 'Client disconnect');
        }

        this.log('🔌 Disconnected');
    }

    /**
     * Reconnect to the WebSocket server
     * This method attempts to reconnect to the WebSocket server after a disconnection.
     * It resets the reconnect attempts and starts the connection process.
     * 
     * @returns {void}
     * @throws {Error} If the socket is already connected or connecting
     */
    reconnect() {
        this.disconnect();
        this.options.reconnect = true;
        this.reconnectAttempts = 0;
        setTimeout(() => this.connect(), 100);
    }

    /**
     * Check if the socket is currently connected
     * This method returns true if the socket is connected and ready to send/receive messages.
     * 
     * @returns {boolean}
     */
    isConnected() {
        return this.state === 'connected' && this.ws && this.ws.readyState === WebSocket.OPEN;
    }

    /**
     * Get the current state of the socket
     * This method returns the current connection state of the socket.
     * 
     * @returns {string} The current state ('disconnected', 'connecting', 'connected', 'reconnecting')
     */
    getState() {
        return this.state;
    }

    /**
     * Get statistics about the socket connection
     * This method returns an object containing various statistics about the socket.
     * 
     * @returns {object} The socket statistics
     */
    getStats() {
        return {
            state: this.state,
            channels: this.channels.size,
            reconnectAttempts: this.reconnectAttempts,
            queuedMessages: this.messageQueue.length,
            lastHeartbeat: this.lastHeartbeat
        };
    }

    /**
    * Log messages to the console if debugging is enabled
    * This method logs messages to the console for debugging purposes.
    * 
    * @param {...*} args The arguments to log
    */
    log(...args) {
        if (this.options.debug) {
            console.log('[PhpSocket]', ...args);
        }
    }
}

/**
 * Channel - Represents a WebSocket channel
 * This class provides methods to bind events, trigger events, and send messages on a specific channel.
 * It is used to manage communication on a specific channel within the WebSocket connection.
 * 
 * @version 1.0.0
 * @license MIT
 * @class
 * @property {string} name The name of the channel
 * @property {PhpSocket} socket The PhpSocket instance associated with this channel
 * Copyright (c) 2025 Shahin Mosyan
 */
class Channel {
    /**
     * Create a new channel
     * This constructor initializes the channel with a name and associates it with a PhpSocket instance.
     * 
     * @param {string} name The name of the channel
     * @param {PhpSocket} socket The PhpSocket instance associated with this channel
     */
    constructor(name, socket) {
        this.name = name;
        this.socket = socket;
        this.subscribed = false;
        this.events = new Map(); // event -> [callbacks]
    }

    /**
     * Bind an event to the channel
     * This method allows you to listen for events that are specific to this channel.
     * It registers a callback function that will be executed when the event is triggered.
     * 
     * @param {string} eventName The name of the event
     * @param {function} callback The callback function to execute when the event is triggered
     * @returns {Channel} The channel instance
     */
    bind(eventName, callback) {
        if (!this.events.has(eventName)) {
            this.events.set(eventName, []);
        }
        this.events.get(eventName).push(callback);
        return this;
    }

    /**
     * Unbind an event from the channel
     * This method allows you to stop listening for a specific event on this channel.
     * 
     * @param {string} eventName The name of the event
     * @param {function} [callback] The callback function to remove
     * @returns {Channel} The channel instance
     */
    unbind(eventName, callback = null) {
        if (!this.events.has(eventName)) return this;

        if (callback) {
            const callbacks = this.events.get(eventName);
            const index = callbacks.indexOf(callback);
            if (index !== -1) {
                callbacks.splice(index, 1);
            }
        } else {
            this.events.delete(eventName);
        }
        return this;
    }

    /**
     * Trigger an event on the channel
     * This method executes all callbacks registered for the specified event on this channel.
     * 
     * @param {string} eventName The name of the event
     * @param {*} data The data to pass to the event callbacks
     */
    trigger(eventName, data = null) {
        if (this.events.has(eventName)) {
            this.events.get(eventName).forEach(callback => {
                try {
                    callback(data);
                } catch (error) {
                    console.error('Error in channel event callback:', error);
                }
            });
        }
        return this;
    }

    /**
     * Send a message on the channel
     * This method triggers an event on the server with the specified event name and data.
     * 
     * @param {string} eventName The name of the event
     * @param {*} data The data to send with the event
     * @returns {Channel} The channel instance
     */
    send(eventName, data) {
        this.socket.trigger(this.name, eventName, data);
        return this;
    }

    /**
     * Subscribe to the channel
     * This method marks the channel as subscribed and sends a subscription request to the server.
     * 
     * @returns {Channel} The channel instance
     */
    unsubscribe() {
        this.subscribed = false;
        this.events.clear();
        return this;
    }

    /**
     * Check if the channel is currently subscribed
     * This method returns true if the channel is subscribed, false otherwise.
     * 
     * @returns {boolean} True if the channel is subscribed, false otherwise
     */
    isSubscribed() {
        return this.subscribed;
    }
}

// Export the PhpSocket and Channel classes for use in other modules
if (typeof window !== 'undefined') {
    window.PhpSocket = PhpSocket;
    window.Channel = Channel;
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { PhpSocket, Channel };
}
