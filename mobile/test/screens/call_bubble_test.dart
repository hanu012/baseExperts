import 'package:flutter/services.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/constants/pref_keys.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_detail_module/vendor_detail_controller.dart';
import 'package:service_marketplace/utils/call_tracker.dart';
import 'package:service_marketplace/utils/injector.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Android exact call timing: the Dart half of MainActivity's
/// call_tracker channel, faked here as the native side would answer.
class _FakeNative {
  bool phone = true;
  bool grantOnRequest = true;
  bool overlay = true;
  List<String> calls = [];
  int? startedLead;
  Map<String, dynamic> poll = {'pending': false, 'leadId': null, 'seconds': null};

  Future<Object?> handle(MethodCall call) async {
    calls.add(call.method);

    switch (call.method) {
      case 'status':
        return {'phone': phone, 'overlay': overlay};
      case 'requestPhonePermission':
        phone = grantOnRequest;
        return grantOnRequest;
      case 'start':
        startedLead = (call.arguments as Map)['leadId'] as int;
        return overlay;
      case 'poll':
        return poll;
      default:
        return null;
    }
  }
}

class _FakeDataSource extends DataSource {
  int leads = 0;
  bool durationAnswers = true;
  List<Map<String, int>> durations = [];

  @override
  Future<CommonResponse?> vendorDetailAPI({required int vendorId, double? latitude, double? longitude}) async {
    return CommonResponse.fromJson({
      'success': true,
      'data': {'id': vendorId, 'business_name': 'Cool Air', 'phone': '9812345678', 'services': [], 'media': [], 'reviews': []},
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> createLeadAPI({
    required int vendorId,
    required int subcategoryId,
    int? zoneId,
    required String channel,
  }) async {
    leads++;
    return CommonResponse.fromJson({'success': true, 'data': {'id': 42}, 'error': null});
  }

  @override
  Future<CommonResponse?> recordLeadDurationAPI({required int leadId, required int durationSeconds}) async {
    durations.add({'lead': leadId, 'seconds': durationSeconds});
    return durationAnswers ? CommonResponse.fromJson({'success': true, 'data': {}, 'error': null}) : null;
  }
}

const _channel = MethodChannel('service_marketplace/call_tracker');

void main() {
  late _FakeNative native;
  late _FakeDataSource api;
  late List<Uri> launched;

  setUp(() async {
    native = _FakeNative();
    api = _FakeDataSource();
    launched = [];
    DataSource.instance = api;
    CallTracker.isSupported = () => true;
    Injector.callTrackingEnabled = true;
    SharedPreferences.setMockInitialValues({});
    Injector.prefs = await SharedPreferences.getInstance();
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(_channel, native.handle);
  });

  tearDown(() {
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(_channel, null);
    CallTracker.isSupported = () => false;
  });

  Future<VendorDetailController> vendor() async {
    final controller = VendorDetailController(
      vendorId: 5,
      subcategoryId: 10,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
    )..launchUrlFn = (url) async {
        launched.add(url);
        return true;
      };
    await controller.fetchAPI();
    return controller;
  }

  testWidgets('with both permissions the call is handed to native timing + bubble', (tester) async {
    final controller = await vendor();
    await controller.callAPI();

    expect(native.startedLead, 42);
    expect(launched.single.scheme, 'tel');

    // The approximate fallback is not also running.
    controller.didChangeAppLifecycleState(AppLifecycleState.paused);
    await controller.finishCallTiming(endedAt: DateTime.now().add(const Duration(minutes: 2)));
    expect(api.durations, isEmpty);

    CallTracker.stopPolling();
    controller.onClose();
  });

  testWidgets('phone permission refused → call still placed, approximate timing', (tester) async {
    native
      ..phone = false
      ..grantOnRequest = false;

    final controller = await vendor();
    await controller.callAPI();

    expect(native.calls, contains('requestPhonePermission'));
    expect(native.startedLead, isNull);
    expect(launched, hasLength(1));

    controller.didChangeAppLifecycleState(AppLifecycleState.paused);
    await controller.finishCallTiming(endedAt: DateTime.now().add(const Duration(minutes: 2)));
    expect(api.durations.single['lead'], 42);

    CallTracker.stopPolling();
    controller.onClose();
  });

  testWidgets('admin switch off → no permission asked, no bubble', (tester) async {
    Injector.callTrackingEnabled = false;
    native.phone = false;

    final controller = await vendor();
    await controller.callAPI();

    expect(native.calls, isEmpty);
    expect(launched, hasLength(1));

    CallTracker.stopPolling();
    controller.onClose();
  });

  testWidgets('bubble prompt: "Turn on" opens Settings and places no call yet', (tester) async {
    native.overlay = false;
    await tester.pumpWidget(const GetMaterialApp(home: SizedBox()));

    final controller = await vendor();
    final calling = controller.callAPI();
    await tester.pumpAndSettle();

    await tester.tap(find.text('callBubbleEnable'));
    await tester.pumpAndSettle();
    await calling;

    expect(native.calls, contains('openOverlaySettings'));
    expect(api.leads, 0);
    expect(launched, isEmpty);
    expect(Injector.prefs?.getBool(PrefKeys.callBubbleAsked), isTrue);

    // Asked once only: the next tap goes straight through (exact timing, no bubble).
    await controller.callAPI();
    expect(api.leads, 1);
    expect(native.startedLead, 42);

    CallTracker.stopPolling();
    controller.onClose();
  });

  testWidgets('bubble prompt: "Not now" still times the call exactly', (tester) async {
    native.overlay = false;
    await tester.pumpWidget(const GetMaterialApp(home: SizedBox()));

    final controller = await vendor();
    final calling = controller.callAPI();
    await tester.pumpAndSettle();

    await tester.tap(find.text('callBubbleNotNow'));
    await tester.pumpAndSettle();
    await calling;

    expect(native.calls, isNot(contains('openOverlaySettings')));
    expect(native.startedLead, 42);
    expect(launched, hasLength(1));

    CallTracker.stopPolling();
    controller.onClose();
  });

  test('flush sends a call the customer never came back from, then clears it', () async {
    native.poll = {'pending': false, 'leadId': 42, 'seconds': 185};

    await CallTracker.flush();

    expect(api.durations.single, {'lead': 42, 'seconds': 185});
    expect(native.calls, contains('clearFinished'));
  });

  test('flush keeps the length for next time when the server is unreachable', () async {
    native.poll = {'pending': false, 'leadId': 42, 'seconds': 185};
    api.durationAnswers = false;

    await CallTracker.flush();

    expect(api.durations, hasLength(1));
    expect(native.calls, isNot(contains('clearFinished')));
  });
}
