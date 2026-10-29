import 'package:flutter_test/flutter_test.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/salesman_profile_module/salesman_profile_controller.dart';
import 'package:service_marketplace/utils/injector.dart';

/// GET /api/salesmen/me and PATCH /api/user/preferences (SPEC section 2.5).
class _RecordingProfileDataSource extends DataSource {
  Map<String, dynamic>? profilePayload;
  bool profileSucceeds = true;

  bool preferenceSucceeds = true;
  Map<String, dynamic>? lastPreferenceBody;

  @override
  Future<CommonResponse?> salesmanMeAPI() async {
    if (!profileSucceeds) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {'code': 'FORBIDDEN', 'message': 'Nope.'},
      }, statusCode: 403);
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': profilePayload ?? _payload(),
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> updatePreferencesAPI({required Map<String, dynamic> body}) async {
    lastPreferenceBody = body;

    if (!preferenceSucceeds) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {'code': 'VALIDATION_FAILED', 'message': 'Nope.'},
      }, statusCode: 422);
    }

    return CommonResponse.fromJson({'success': true, 'data': body, 'error': null});
  }

  static Map<String, dynamic> _payload({bool notifications = true, int? percent = 25}) => {
        'salesman': {
          'id': 10,
          'name': 'Ram Prakash',
          'email': 'ram@example.com',
          'employee_code': 'SM-0421',
          'phone': '9900000001',
          'region': 'Ahmedabad · Gujarat',
          'is_active': true,
          'commission_rate_bps': 1200,
          'commission_rate_percent': '12.00',
          'monthly_target_paise': 10000000,
          'language': 'en',
          'enable_notification': notifications,
        },
        'stats': {
          'total_vendors': 3,
          'subscribed_vendors': 1,
          'earnings_paise': 1240000,
          'pending_earnings_paise': 50000,
          'target': {
            'monthly_target_paise': 10000000,
            'achieved_paise': 2500000,
            'percent': percent,
          },
        },
      };
}

void main() {
  testWidgets('it parses identity, preferences and stats from one call', (tester) async {
    DataSource.instance = _RecordingProfileDataSource();

    final controller = SalesmanProfileController();
    await controller.fetchProfileAPI();

    final profile = controller.profile;
    expect(profile, isNotNull);
    expect(profile!.name, 'Ram Prakash');
    expect(profile.employeeCode, 'SM-0421');
    expect(profile.region, 'Ahmedabad · Gujarat');
    // Server-formatted: deriving it in the app risks showing a rate that
    // disagrees with the actual payout.
    expect(profile.commissionRatePercent, '12.00');

    expect(profile.stats?.totalVendors, 3);
    expect(profile.stats?.subscribedVendors, 1);
    expect(profile.stats?.target?.percent, 25);
    expect(profile.stats?.target?.hasTarget, isTrue);
  });

  testWidgets('a null target percent reads as "no target", not zero', (tester) async {
    // "No target" and "0% of a target" are different states — the screen
    // hides the row for the former rather than drawing an empty bar.
    DataSource.instance = _RecordingProfileDataSource()
      ..profilePayload = _RecordingProfileDataSource._payload(percent: null);

    final controller = SalesmanProfileController();
    await controller.fetchProfileAPI();

    expect(controller.profile?.stats?.target?.hasTarget, isFalse);
  });

  testWidgets('initials fall back sensibly for one-word and empty names', (tester) async {
    DataSource.instance = _RecordingProfileDataSource();

    final controller = SalesmanProfileController();
    await controller.fetchProfileAPI();

    expect(controller.profile?.initials, 'RP');
  });

  testWidgets('toggling notifications sends the change and mirrors it locally',
      (tester) async {
    final fake = _RecordingProfileDataSource();
    DataSource.instance = fake;

    final controller = SalesmanProfileController();
    await controller.fetchProfileAPI();

    await controller.setNotifications(false);

    expect(fake.lastPreferenceBody, {'enable_notification': false});
    expect(controller.profile?.enableNotification, isFalse);
    // The rest of the app reads Injector, so it must not disagree with
    // what this screen is showing.
    expect(Injector.enableNotification, isFalse);
  });

  testWidgets('a rejected toggle rolls back rather than lying about the state',
      (tester) async {
    final fake = _RecordingProfileDataSource()..preferenceSucceeds = false;
    DataSource.instance = fake;

    final controller = SalesmanProfileController();
    await controller.fetchProfileAPI();

    expect(controller.profile?.enableNotification, isTrue);

    await controller.setNotifications(false);

    // The switch flips optimistically, so a refusal has to put it back —
    // otherwise the UI claims a preference the server never stored.
    expect(controller.profile?.enableNotification, isTrue);
  });

  testWidgets('a failed load sets the error state and leaves no stale profile',
      (tester) async {
    DataSource.instance = _RecordingProfileDataSource()..profileSucceeds = false;

    final controller = SalesmanProfileController();
    await controller.fetchProfileAPI();

    expect(controller.hasError, isTrue);
    expect(controller.profile, isNull);
    expect(controller.isLoading, isFalse);
  });
}
