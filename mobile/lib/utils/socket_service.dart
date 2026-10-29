import 'dart:async';

import 'package:web_socket_channel/web_socket_channel.dart';

import '../constants/app.export.dart';

/// One live event from the server: its name (e.g. `account.approval`) and
/// its payload.
class SocketEvent {
  const SocketEvent(this.event, this.data);

  final String event;
  final Map<String, dynamic> data;
}

/// The live socket to the backend, shaped like the ehhs patient app's
/// SocketService: one shared connection, reconnect with backoff, a ping to
/// notice a dead line, a fresh connection whenever the app comes back to
/// the foreground, and events handed out through [eventStream] and [on].
///
/// The server is Laravel Reverb, which speaks the Pusher protocol — JSON
/// messages `{event, data, channel}` over a plain WebSocket. After
/// connecting, the app subscribes to its own private channel
/// (`private-App.Models.User.{id}`), signed by POST /api/broadcasting/auth
/// with the normal Sanctum token, so it only ever hears its own events.
///
/// Where to connect comes from GET /api/settings (`realtime`). When that is
/// null the admin has live events off and [connect] does nothing — screens
/// that care also re-check on [appResumedStream], so they still work, just
/// not instantly.
///
/// [connect] is safe to call from anywhere and any number of times.
class SocketService extends GetxService with WidgetsBindingObserver {
  SocketService._();

  static SocketService? _instance;
  static SocketService get instance => _instance ??= SocketService._();

  static bool _observerAdded = false;

  WebSocketChannel? _channel;
  StreamSubscription<dynamic>? _subscription;
  Timer? _pingTimer;
  Timer? _pongTimer;
  Timer? _reconnectTimer;

  final RxBool isSocketConnected = false.obs;
  bool get isConnected => isSocketConnected.value;
  bool _isConnecting = false;

  /// Set by [connect], cleared by [disconnect]: whether a dropped line
  /// should be redialled at all.
  bool _wanted = false;

  int _reconnectAttempts = 0;
  static const int _maxReconnectAttempts = 10;

  /// Reverb's id for this connection, needed to sign a subscription.
  String? socketId;

  /// The channel this connection is subscribed to, once it is.
  String? subscribedChannel;

  /// How often to ping. Reverb tells us its activity timeout on connect;
  /// pinging inside it keeps the connection from being dropped as idle.
  Duration _pingInterval = const Duration(seconds: 30);

  final StreamController<SocketEvent> _eventController =
      StreamController<SocketEvent>.broadcast();

  /// Every event from the server, for anyone who wants all of them.
  Stream<SocketEvent> get eventStream => _eventController.stream;

  final StreamController<bool> _appResumedController = StreamController<bool>.broadcast();

  /// Fires when the app comes back to the foreground. Events sent while it
  /// was away may have been missed, so screens re-check their state here.
  Stream<bool> get appResumedStream => _appResumedController.stream;

  final Map<String, Map<String, void Function(SocketEvent)>> _listeners = {};

  /// Registers once with GetX and the app lifecycle. Called at startup.
  static SocketService init() {
    final service = instance;

    if (!Get.isRegistered<SocketService>()) {
      Get.put<SocketService>(service, permanent: true);
    }

    if (!_observerAdded) {
      WidgetsBinding.instance.addObserver(service);
      _observerAdded = true;
    }

    return service;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) {
      return;
    }

    // Phones quietly kill sockets in the background; a fresh one is cheaper
    // than finding out the old one is dead at the next ping.
    if (_wanted) {
      forceReconnect();
    }

    if (!_appResumedController.isClosed) {
      _appResumedController.add(true);
    }
  }

  // ── Listeners ──────────────────────────────────────────────────────────

  /// Calls [callback] for every [event]. [listenerId] — usually the
  /// controller's name — keeps one controller from registering twice, and
  /// is what [off] removes.
  void on(String event, String listenerId, void Function(SocketEvent) callback) {
    (_listeners[event] ??= {})[listenerId] = callback;
  }

  void off(String event, String listenerId) {
    _listeners[event]?.remove(listenerId);
  }

  // ── Connection ─────────────────────────────────────────────────────────

  Future<void> connect() async {
    _wanted = true;

    if (_isConnecting || (_channel != null && isConnected)) {
      return;
    }

    final userId = Injector.userData?.id;
    if (!Injector.isLoggedIn || userId == null) {
      return;
    }

    _isConnecting = true;

    try {
      final uri = await _socketUri();

      if (uri == null) {
        // Live events are off on the server, or it could not be asked.
        _isConnecting = false;
        return;
      }

      _log('Connecting to $uri');
      final channel = WebSocketChannel.connect(uri);
      _channel = channel;

      _subscription = channel.stream.listen(
        handleMessage,
        onError: (Object error) {
          _log('Socket error: $error');
          _onLost();
        },
        onDone: () {
          _log('Socket closed');
          _onLost();
        },
        cancelOnError: false,
      );

      await channel.ready;
      // Connected at the TCP level; "connected" for us is Reverb's
      // pusher:connection_established, handled in handleMessage.
    } catch (e) {
      _log('Socket connect failed: $e');
      _onLost();
    } finally {
      _isConnecting = false;
    }
  }

  /// Drops whatever is there and dials again straight away.
  Future<void> forceReconnect() async {
    _reconnectAttempts = 0;
    _reconnectTimer?.cancel();
    _cleanupConnection();
    await connect();
  }

  /// On sign-out: stop, and stay stopped until [connect] is called again.
  void disconnect() {
    _wanted = false;
    _reconnectTimer?.cancel();
    _reconnectTimer = null;
    _cleanupConnection();
  }

  Future<Uri?> _socketUri() async {
    final response = await DataSource.instance.settingsAPI();
    final data = response?.data;

    // The API itself is unreachable: no point redialling a socket on a
    // timer. The next resume (or connect() from a screen) tries again.
    if (!(response?.isSuccess ?? false) || data is! Map<String, dynamic>) {
      _log('Settings unavailable — not connecting for now');
      return null;
    }

    final realtime = data['realtime'];
    if (realtime is! Map<String, dynamic> || realtime['key'] is! String) {
      return null;
    }

    final secure = realtime['scheme'] == 'https';

    return Uri(
      scheme: secure ? 'wss' : 'ws',
      host: reachableHost(realtime['host'] as String, apiBaseUrlForTesting ?? DataSource.baseUrl),
      port: (realtime['port'] as num?)?.toInt() ?? (secure ? 443 : 80),
      path: '/app/${realtime['key']}',
      queryParameters: {
        'protocol': '7',
        'client': 'flutter',
        'version': '1.0',
        'flash': 'false',
      },
    );
  }

  /// The socket host the phone can actually reach.
  ///
  /// With REVERB_PUBLIC_HOST unset the server reports its own loopback
  /// (127.0.0.1 / localhost), which on a phone or emulator is the phone
  /// itself — the socket then silently never connects and no live event
  /// (e.g. account.approval) ever arrives. Reverb runs beside the API, so
  /// a loopback answer is swapped for the host the app already reaches the
  /// API on (10.0.2.2 on the emulator, the LAN IP on a device).
  /// Stands in for DataSource.baseUrl in tests that run a local Reverb.
  @visibleForTesting
  static String? apiBaseUrlForTesting;

  @visibleForTesting
  static String reachableHost(String reported, String apiBaseUrl) {
    const loopback = {'127.0.0.1', 'localhost', '0.0.0.0', '::1'};

    if (!loopback.contains(reported.toLowerCase())) {
      return reported;
    }

    final apiHost = Uri.tryParse(apiBaseUrl)?.host ?? '';

    return apiHost.isEmpty ? reported : apiHost;
  }

  // ── Messages ───────────────────────────────────────────────────────────

  @visibleForTesting
  void handleMessage(dynamic raw) {
    // Anything at all from the server proves the line is alive.
    _pongTimer?.cancel();
    _pongTimer = null;

    final Map<String, dynamic> message;
    try {
      message = jsonDecode(raw.toString()) as Map<String, dynamic>;
    } catch (e) {
      _log('Unreadable socket message: $raw');
      return;
    }

    final event = message['event']?.toString() ?? '';
    final data = _payload(message['data']);

    switch (event) {
      case 'pusher:connection_established':
        socketId = data['socket_id']?.toString();
        final timeout = (data['activity_timeout'] as num?)?.toInt();
        if (timeout != null && timeout > 5) {
          _pingInterval = Duration(seconds: timeout - 5);
        }
        isSocketConnected.value = true;
        _reconnectAttempts = 0;
        _log('Connected, socket id $socketId');
        _startPingTimer();
        _subscribeToOwnChannel();
        return;

      case 'pusher:ping':
        _send({'event': 'pusher:pong', 'data': {}});
        return;

      case 'pusher:pong':
        return;

      case 'pusher_internal:subscription_succeeded':
        subscribedChannel = message['channel']?.toString();
        _log('Subscribed to $subscribedChannel');
        return;

      case 'pusher:error':
        _log('Server error: $data');
        return;
    }

    final socketEvent = SocketEvent(event, data);
    _log('Event $event: $data');

    if (!_eventController.isClosed) {
      _eventController.add(socketEvent);
    }

    // A copy, so a listener that removes itself mid-call is safe.
    for (final callback in [...?_listeners[event]?.values]) {
      callback(socketEvent);
    }
  }

  /// Pusher sends `data` as a JSON string inside the JSON message.
  Map<String, dynamic> _payload(dynamic data) {
    if (data is Map<String, dynamic>) {
      return data;
    }

    if (data is String && data.isNotEmpty) {
      try {
        final decoded = jsonDecode(data);
        if (decoded is Map<String, dynamic>) {
          return decoded;
        }
      } catch (_) {}
    }

    return {};
  }

  Future<void> _subscribeToOwnChannel() async {
    final userId = Injector.userData?.id;
    final id = socketId;

    if (userId == null || id == null) {
      return;
    }

    final channelName = 'private-App.Models.User.$userId';
    final auth = await DataSource.instance.broadcastingAuthAPI(
      socketId: id,
      channelName: channelName,
    );

    if (auth == null) {
      _log('Subscription to $channelName was refused');
      return;
    }

    _send({
      'event': 'pusher:subscribe',
      'data': {'auth': auth, 'channel': channelName},
    });
  }

  void _send(Map<String, dynamic> message) {
    try {
      _channel?.sink.add(jsonEncode(message));
    } catch (e) {
      _log('Socket send failed: $e');
    }
  }

  // ── Keep-alive and reconnect ───────────────────────────────────────────

  void _startPingTimer() {
    _pingTimer?.cancel();
    _pingTimer = Timer.periodic(_pingInterval, (_) {
      if (!isConnected) {
        return;
      }

      _send({'event': 'pusher:ping', 'data': {}});

      // No answer in 10 seconds: the line is dead even if nobody said so.
      _pongTimer?.cancel();
      _pongTimer = Timer(const Duration(seconds: 10), () {
        _log('No pong — reconnecting');
        _onLost();
      });
    });
  }

  void _onLost() {
    _cleanupConnection();

    if (!_wanted || _reconnectTimer?.isActive == true) {
      return;
    }

    // 2s, 4s, 8s … capped at 30s; then every 60s rather than giving up,
    // so the socket comes back by itself when the network does.
    final seconds = _reconnectAttempts < _maxReconnectAttempts
        ? (2 * (1 << _reconnectAttempts)).clamp(2, 30)
        : 60;
    _reconnectAttempts++;

    _log('Reconnecting in ${seconds}s (attempt $_reconnectAttempts)');
    _reconnectTimer = Timer(Duration(seconds: seconds), connect);
  }

  void _cleanupConnection() {
    _pingTimer?.cancel();
    _pingTimer = null;
    _pongTimer?.cancel();
    _pongTimer = null;
    _subscription?.cancel();
    _subscription = null;
    try {
      _channel?.sink.close();
    } catch (_) {}
    _channel = null;
    socketId = null;
    subscribedChannel = null;
    isSocketConnected.value = false;
    _isConnecting = false;
  }

  void _log(String message) {
    if (kDebugMode) {
      print('🔌 Socket: $message');
    }
  }

  @override
  void onClose() {
    WidgetsBinding.instance.removeObserver(this);
    _observerAdded = false;
    disconnect();
    _eventController.close();
    _appResumedController.close();
    super.onClose();
  }
}
