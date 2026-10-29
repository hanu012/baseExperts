import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/common_model/user_model.dart';
import 'package:service_marketplace/constants/flavor_config.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/account_verification_module/account_verification_controller.dart';
import 'package:service_marketplace/utils/injector.dart';
import 'package:service_marketplace/utils/socket_service.dart';

/// The live socket to Laravel Reverb, and the verification screen it
/// unblocks.
class _RealtimeDataSource extends DataSource {
  /// GET /settings → realtime; null means live events are off.
  Map<String, dynamic>? realtime;

  Map<String, String>? authRequest;
  int userCalls = 0;
  String approvalStatus = 'pending';

  @override
  Future<CommonResponse?> settingsAPI() async => CommonResponse.fromJson({
        'success': true,
        'data': {'realtime': realtime},
        'error': null,
      });

  @override
  Future<String?> broadcastingAuthAPI({
    required String socketId,
    required String channelName,
  }) async {
    authRequest = {'socket_id': socketId, 'channel_name': channelName};
    return 'app-key:signature';
  }

  @override
  Future<CommonResponse?> userAPI() async {
    userCalls++;

    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'id': 7,
        'name': 'Asha',
        'email': 'asha@example.com',
        'role': 'vendor',
        'approval_status': approvalStatus,
        'approval_note': approvalStatus == 'rejected' ? 'Blurry ID photo' : null,
      },
      'error': null,
    });
  }
}

void _signIn() {
  Injector.accessToken = 'token';
  Injector.userData = UserModel.fromJson({'id': 7, 'role': 'vendor', 'approval_status': 'pending'});
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    _signIn();
    // The stand-in Reverb below listens on this machine's loopback, so the
    // API is "here" too and the loopback host is kept as reported.
    SocketService.apiBaseUrlForTesting = 'http://127.0.0.1:8000/api/';
  });

  tearDown(() {
    SocketService.instance.disconnect();
    SocketService.apiBaseUrlForTesting = null;
    Get.reset();
  });

  /// REVERB_PUBLIC_HOST unset → the server reports 127.0.0.1, which on a
  /// phone is the phone itself: the socket never connected and the
  /// verification screen never heard the approval.
  test('a loopback socket host is swapped for the host the API is reached on', () {
    expect(SocketService.reachableHost('127.0.0.1', 'http://10.0.2.2:8000/api/'), '10.0.2.2');
    expect(SocketService.reachableHost('localhost', 'http://192.168.1.5:8000/api/'), '192.168.1.5');
    expect(SocketService.reachableHost('ws.example.com', 'http://10.0.2.2:8000/api/'), 'ws.example.com');
    expect(SocketService.reachableHost('127.0.0.1', 'http://127.0.0.1:8000/api/'), '127.0.0.1');
  });

  testWidgets('with no live socket the verification screen re-checks by itself', (tester) async {
    FlavorConfig.initialize(FlavorConfig.vendor);
    final fake = _RealtimeDataSource()..realtime = null;
    DataSource.instance = fake;

    await tester.pumpWidget(const GetMaterialApp(home: Scaffold(body: SizedBox())));
    final controller = Get.put(AccountVerificationController());
    expect(fake.userCalls, 0);

    await tester.pump(AccountVerificationController.pollInterval);
    await tester.pump();

    expect(fake.userCalls, 1);
    expect(controller.status, 'pending');

    Get.delete<AccountVerificationController>();
    await tester.pump(AccountVerificationController.pollInterval);
    expect(fake.userCalls, 1);
  });

  test('it speaks the Reverb (Pusher) protocol end to end', () async {
    // A stand-in Reverb on a local port.
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final fromApp = StreamController<Map<String, dynamic>>.broadcast();
    WebSocket? serverSide;
    Uri? requested;

    server.listen((request) async {
      requested = request.uri;
      final socket = await WebSocketTransformer.upgrade(request);
      serverSide = socket;
      socket.listen((raw) => fromApp.add(jsonDecode(raw as String) as Map<String, dynamic>));
      socket.add(jsonEncode({
        'event': 'pusher:connection_established',
        'data': jsonEncode({'socket_id': '123.456', 'activity_timeout': 30}),
      }));
    });

    final fake = _RealtimeDataSource()
      ..realtime = {'driver': 'reverb', 'key': 'app-key', 'host': '127.0.0.1', 'port': server.port, 'scheme': 'http'};
    DataSource.instance = fake;

    final service = SocketService.instance;
    final subscribe = fromApp.stream.firstWhere((m) => m['event'] == 'pusher:subscribe');

    await service.connect();

    // Subscribes to its OWN private channel, signed by the backend.
    final message = await subscribe.timeout(const Duration(seconds: 5));
    expect(requested?.path, '/app/app-key');
    expect(requested?.queryParameters['protocol'], '7');
    expect(fake.authRequest, {'socket_id': '123.456', 'channel_name': 'private-App.Models.User.7'});
    expect(message['data'], {'auth': 'app-key:signature', 'channel': 'private-App.Models.User.7'});
    expect(service.isConnected, isTrue);

    // A server event reaches the app with its JSON-string payload decoded.
    final received = service.eventStream.first;
    serverSide!.add(jsonEncode({
      'event': 'account.approval',
      'channel': 'private-App.Models.User.7',
      'data': jsonEncode({'approval_status': 'approved', 'approval_note': null}),
    }));
    final event = await received.timeout(const Duration(seconds: 5));
    expect(event.event, 'account.approval');
    expect(event.data['approval_status'], 'approved');

    // Answers the server's keep-alive.
    final pong = fromApp.stream.firstWhere((m) => m['event'] == 'pusher:pong');
    serverSide!.add(jsonEncode({'event': 'pusher:ping', 'data': '{}'}));
    await pong.timeout(const Duration(seconds: 5));

    service.disconnect();
    expect(service.isConnected, isFalse);
    await server.close(force: true);
    await fromApp.close();
  });

  test('with live events off on the server it does not connect', () async {
    final fake = _RealtimeDataSource()..realtime = null;
    DataSource.instance = fake;

    await SocketService.instance.connect();

    expect(SocketService.instance.isConnected, isFalse);
    expect(fake.authRequest, isNull);
  });

  test('signed out, it does not even ask where to connect', () async {
    Injector.accessToken = '';
    final fake = _RealtimeDataSource()..realtime = {'key': 'k', 'host': '127.0.0.1', 'port': 1};
    DataSource.instance = fake;

    await SocketService.instance.connect();

    expect(SocketService.instance.isConnected, isFalse);
  });

  testWidgets('a pushed decision updates the verification screen without a tap', (tester) async {
    FlavorConfig.initialize(FlavorConfig.vendor);
    final fake = _RealtimeDataSource()..approvalStatus = 'rejected';
    DataSource.instance = fake;

    await tester.pumpWidget(const GetMaterialApp(home: Scaffold(body: SizedBox())));
    final controller = Get.put(AccountVerificationController());
    expect(controller.status, 'pending');

    // What Reverb delivers when the admin presses Reject.
    SocketService.instance.handleMessage(jsonEncode({
      'event': 'account.approval',
      'channel': 'private-App.Models.User.7',
      'data': jsonEncode({'approval_status': 'rejected', 'approval_note': 'Blurry ID photo'}),
    }));
    await tester.pump(const Duration(seconds: 4));

    expect(fake.userCalls, 1);
    expect(controller.status, 'rejected');
    expect(controller.note, 'Blurry ID photo');
    expect(Injector.userData?.approvalStatus, 'rejected');

    // Gone with the screen: a later push does nothing.
    Get.delete<AccountVerificationController>();
    SocketService.instance.handleMessage(jsonEncode({'event': 'account.approval', 'data': '{}'}));
    await tester.pump();
    expect(fake.userCalls, 1);
  });
}
