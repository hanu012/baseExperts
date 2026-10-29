import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/common_model/lead_model.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_detail_module/vendor_detail_controller.dart';
import 'package:service_marketplace/utils/injector.dart';
import 'package:service_marketplace/utils/utils.dart';

/// Approximate call length: tap Call → back in the app, reported once to
/// POST leads/{id}/duration, and only while the admin switch is on.
class _FakeCallDataSource extends DataSource {
  List<Map<String, int>> durations = [];

  @override
  Future<CommonResponse?> vendorDetailAPI({required int vendorId, double? latitude, double? longitude}) async {
    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'id': vendorId,
        'business_name': 'Cool Air Services',
        'phone': '9812345678',
        'services': [],
        'media': [],
        'reviews': [],
      },
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
    return CommonResponse.fromJson({
      'success': true,
      'data': {'id': 42, 'created_at': '2026-10-14T00:00:00Z'},
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> recordLeadDurationAPI({required int leadId, required int durationSeconds}) async {
    durations.add({'lead': leadId, 'seconds': durationSeconds});
    return CommonResponse.fromJson({'success': true, 'data': {}, 'error': null});
  }
}

Future<VendorDetailController> _calledVendor(_FakeCallDataSource fake) async {
  DataSource.instance = fake;
  final controller = VendorDetailController(
    vendorId: 5,
    subcategoryId: 10,
    zoneId: 7,
    latitude: 23.02,
    longitude: 72.52,
  )..launchUrlFn = (_) async => true;
  await controller.fetchAPI();
  await controller.callAPI();
  return controller;
}

void main() {
  tearDown(() => Injector.callTrackingEnabled = true);

  testWidgets('a call is timed and reported once against its lead', (tester) async {
    final fake = _FakeCallDataSource();
    final controller = await _calledVendor(fake);

    controller.didChangeAppLifecycleState(AppLifecycleState.paused);
    await controller.finishCallTiming(endedAt: DateTime.now().add(const Duration(seconds: 125)));
    // A second resume after reporting must not send again.
    await controller.finishCallTiming();

    expect(fake.durations, hasLength(1));
    expect(fake.durations.first['lead'], 42);
    expect(fake.durations.first['seconds'], inInclusiveRange(124, 126));

    controller.onClose();
  });

  testWidgets('the length is capped at an hour', (tester) async {
    final fake = _FakeCallDataSource();
    final controller = await _calledVendor(fake);

    await controller.finishCallTiming(endedAt: DateTime.now().add(const Duration(hours: 5)));

    expect(fake.durations.single['seconds'], 3600);

    controller.onClose();
  });

  testWidgets('resuming without having left the app reports nothing', (tester) async {
    final fake = _FakeCallDataSource();
    final controller = await _calledVendor(fake);

    controller.didChangeAppLifecycleState(AppLifecycleState.resumed);
    await tester.pump();

    expect(fake.durations, isEmpty);

    controller.onClose();
  });

  testWidgets('with the admin switch off the call still goes out, untimed', (tester) async {
    Injector.callTrackingEnabled = false;
    final fake = _FakeCallDataSource();
    var launched = 0;

    DataSource.instance = fake;
    final controller = VendorDetailController(
      vendorId: 5,
      subcategoryId: 10,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
    )..launchUrlFn = (_) async => ++launched > 0;
    await controller.fetchAPI();
    await controller.callAPI();

    controller.didChangeAppLifecycleState(AppLifecycleState.paused);
    await controller.finishCallTiming(endedAt: DateTime.now().add(const Duration(minutes: 2)));

    expect(launched, 1);
    expect(fake.durations, isEmpty);

    controller.onClose();
  });

  test('approxCallLength reads as minutes, empty when not reported', () {
    expect(Utils.approxCallLength(null), '');
    expect(Utils.approxCallLength(20), startsWith('<1 '));
    expect(Utils.approxCallLength(185), startsWith('~3 '));
  });

  test('call models parse the API shape', () {
    final call = CustomerCallModel.fromJson({
      'id': 1,
      'vendor_id': 5,
      'vendor_name': 'Cool Air Services',
      'subcategory_id': 10,
      'subcategory_name': 'AC repair',
      'channel': 'call',
      'call_duration_seconds': 95,
      'created_at': '2026-10-14T05:00:00Z',
    });
    expect(call.vendorId, 5);
    expect(call.callDurationSeconds, 95);

    final summary = LeadsSummaryModel.fromJson({
      'calls_today': 3,
      'whatsapp_today': 1,
      'calls_last_7_days': 9,
      'average_call_seconds': 140,
    });
    expect(summary.callsToday, 3);
    expect(summary.averageCallSeconds, 140);

    final lead = LeadModel.fromJson({'id': 1, 'channel': 'call', 'call_duration_seconds': 60});
    expect(lead.callDurationSeconds, 60);
  });
}
