import 'package:flutter_test/flutter_test.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_detail_module/vendor_detail_controller.dart';

/// Fakes GET /vendors/{vendor}/detail and POST /leads (tasks 5.3/5.4).
/// `leadShouldFail` is the whole point of this file — it lets a test
/// prove the SPEC section 4 item 7 ordering rule: the dialer/WhatsApp
/// intent must never open when the lead write didn't durably succeed.
class _FakeVendorDetailDataSource extends DataSource {
  bool leadShouldFail = false;
  List<String> capturedLeadChannels = [];

  bool reviewShouldFail = false;
  bool reviewShouldFailWithNoEligibleLead = false;
  int fetchCount = 0;
  List<Map<String, dynamic>> capturedReviews = [];

  bool toggleShouldFail = false;
  List<int> capturedToggleVendorIds = [];

  bool reportShouldFail = false;
  List<Map<String, dynamic>> capturedReports = [];

  @override
  Future<CommonResponse?> toggleFavoriteAPI({required int vendorId}) async {
    capturedToggleVendorIds.add(vendorId);

    if (toggleShouldFail) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {'code': 'NOT_FOUND', 'message': 'No customer profile.'},
      });
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': {'is_favorite': true},
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> reportVendorAPI({required int vendorId, required String reason}) async {
    capturedReports.add({'vendorId': vendorId, 'reason': reason});

    if (reportShouldFail) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {'code': 'SERVER_ERROR', 'message': 'Something broke'},
      });
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': {'message': 'Thanks — this vendor has been reported for review.'},
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> vendorDetailAPI({
    required int vendorId,
    double? latitude,
    double? longitude,
  }) async {
    fetchCount++;

    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'id': vendorId,
        'business_name': 'Cool Air Services',
        'address': '12 MG Road',
        'latitude': 23.03,
        'longitude': 72.55,
        'phone': '9812345678',
        'shop_photo_url': null,
        'rating_avg': fetchCount > 1 ? 5 : 0,
        'rating_count': fetchCount > 1 ? 1 : 0,
        'distance_km': 1.2,
        'services': [],
        'media': [],
        'reviews': capturedReviews,
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
    capturedLeadChannels.add(channel);

    if (leadShouldFail) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {'code': 'SERVER_ERROR', 'message': 'Something broke'},
      });
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': {'id': 99, 'created_at': '2026-08-23T00:00:00Z'},
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> submitReviewAPI({
    required int vendorId,
    required int rating,
    String? comment,
  }) async {
    if (reviewShouldFailWithNoEligibleLead) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {
          'code': 'VALIDATION_FAILED',
          'message': 'Validation failed.',
          'fields': {
            'vendor_id': ['No recent contact with this vendor was found within the last 30 days.'],
          },
        },
      });
    }

    if (reviewShouldFail) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {'code': 'SERVER_ERROR', 'message': 'Something broke'},
      });
    }

    capturedReviews.add({
      'id': 1,
      'rating': rating,
      'comment': comment,
      'customer_name': 'Priya S.',
      'vendor_reply': null,
      'replied_at': null,
      'created_at': '2026-08-23T00:00:00Z',
    });

    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'id': 1,
        'rating': rating,
        'comment': comment,
        'customer_name': 'Priya S.',
        'vendor_reply': null,
        'replied_at': null,
        'created_at': '2026-08-23T00:00:00Z',
      },
      'error': null,
    });
  }
}

/// Records every URI the controller would have launched, standing in
/// for url_launcher's platform channel.
class _RecordingLauncher {
  List<Uri> launched = [];

  Future<bool> call(Uri url) async {
    launched.add(url);
    return true;
  }
}

void main() {
  testWidgets('fetchAPI populates the vendor', (tester) async {
    DataSource.instance = _FakeVendorDetailDataSource();

    final controller = VendorDetailController(
      vendorId: 5,
      subcategoryId: 10,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
    );
    await controller.fetchAPI();

    expect(controller.isLoading, isFalse);
    expect(controller.vendor?.businessName, 'Cool Air Services');
    expect(controller.vendor?.phone, '9812345678');
    expect(controller.vendor?.distanceKm, 1.2);

    controller.onClose();
  });

  // ── The load-bearing case: SPEC section 4 item 7's ordering rule ─────

  testWidgets('callAPI launches the dialer only after the lead write succeeds', (tester) async {
    final fake = _FakeVendorDetailDataSource();
    DataSource.instance = fake;
    final launcher = _RecordingLauncher();

    final controller = VendorDetailController(
      vendorId: 5,
      subcategoryId: 10,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
    )..launchUrlFn = launcher.call;
    await controller.fetchAPI();

    await controller.callAPI();

    expect(fake.capturedLeadChannels, ['call']);
    expect(launcher.launched, hasLength(1));
    expect(launcher.launched.first.scheme, 'tel');
    expect(launcher.launched.first.path, '9812345678');

    controller.onClose();
  });

  testWidgets('callAPI does NOT launch the dialer when the lead write fails', (tester) async {
    final fake = _FakeVendorDetailDataSource()..leadShouldFail = true;
    DataSource.instance = fake;
    final launcher = _RecordingLauncher();

    final controller = VendorDetailController(
      vendorId: 5,
      subcategoryId: 10,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
    )..launchUrlFn = launcher.call;
    await controller.fetchAPI();

    await controller.callAPI();

    // The write was attempted (proving this isn't a silent no-op)...
    expect(fake.capturedLeadChannels, ['call']);
    // ...but the intent absolutely must not have opened.
    expect(launcher.launched, isEmpty);

    controller.onClose();
  });

  testWidgets('whatsappAPI launches wa.me only after the lead write succeeds', (tester) async {
    final fake = _FakeVendorDetailDataSource();
    DataSource.instance = fake;
    final launcher = _RecordingLauncher();

    final controller = VendorDetailController(
      vendorId: 5,
      subcategoryId: 10,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
    )..launchUrlFn = launcher.call;
    await controller.fetchAPI();

    await controller.whatsappAPI();

    expect(fake.capturedLeadChannels, ['whatsapp']);
    expect(launcher.launched, hasLength(1));
    expect(launcher.launched.first.toString(), 'https://wa.me/9812345678');

    controller.onClose();
  });

  testWidgets('whatsappAPI does NOT open wa.me when the lead write fails', (tester) async {
    final fake = _FakeVendorDetailDataSource()..leadShouldFail = true;
    DataSource.instance = fake;
    final launcher = _RecordingLauncher();

    final controller = VendorDetailController(
      vendorId: 5,
      subcategoryId: 10,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
    )..launchUrlFn = launcher.call;
    await controller.fetchAPI();

    await controller.whatsappAPI();

    expect(fake.capturedLeadChannels, ['whatsapp']);
    expect(launcher.launched, isEmpty);

    controller.onClose();
  });

  testWidgets('a lead write exception is treated as failure, not a launch', (tester) async {
    DataSource.instance = _ThrowingLeadDataSource();
    final launcher = _RecordingLauncher();

    final controller = VendorDetailController(
      vendorId: 5,
      subcategoryId: 10,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
    )..launchUrlFn = launcher.call;
    await controller.fetchAPI();

    await controller.callAPI();

    expect(launcher.launched, isEmpty);
    expect(controller.isSubmittingLead, isFalse);

    controller.onClose();
  });

  // ── Reviews (SPEC section 9, task 5.5) ──────────────────────────────

  testWidgets('fetchAPI parses reviews into the model', (tester) async {
    final fake = _FakeVendorDetailDataSource()
      ..capturedReviews.add({
        'id': 1,
        'rating': 4,
        'comment': 'Good work',
        'customer_name': 'Priya S.',
        'vendor_reply': 'Thanks!',
        'replied_at': '2026-08-23T01:00:00Z',
        'created_at': '2026-08-23T00:00:00Z',
      });
    DataSource.instance = fake;

    final controller = VendorDetailController(
      vendorId: 5,
      subcategoryId: 10,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
    );
    await controller.fetchAPI();

    expect(controller.vendor?.reviews, hasLength(1));
    expect(controller.vendor?.reviews.first.customerName, 'Priya S.');
    expect(controller.vendor?.reviews.first.rating, 4);
    expect(controller.vendor?.reviews.first.vendorReply, 'Thanks!');

    controller.onClose();
  });

  testWidgets('submitReviewAPI re-fetches on success so the new review and rating show', (
    tester,
  ) async {
    final fake = _FakeVendorDetailDataSource();
    DataSource.instance = fake;

    final controller = VendorDetailController(
      vendorId: 5,
      subcategoryId: 10,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
    );
    await controller.fetchAPI();
    expect(controller.vendor?.reviews, isEmpty);

    final succeeded = await controller.submitReviewAPI(rating: 5, comment: 'Great!');

    expect(succeeded, isTrue);
    expect(controller.isSubmittingReview, isFalse);
    // The re-fetch picked up both the new review and the recalculated
    // rating — proving this isn't a locally-inserted stand-in.
    expect(controller.vendor?.reviews, hasLength(1));
    expect(controller.vendor?.ratingCount, 1);
    expect(fake.fetchCount, 2);

    controller.onClose();
  });

  testWidgets('submitReviewAPI surfaces the no-eligible-lead message specifically', (
    tester,
  ) async {
    final fake = _FakeVendorDetailDataSource()..reviewShouldFailWithNoEligibleLead = true;
    DataSource.instance = fake;

    final controller = VendorDetailController(
      vendorId: 5,
      subcategoryId: 10,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
    );
    await controller.fetchAPI();

    final succeeded = await controller.submitReviewAPI(rating: 5);

    expect(succeeded, isFalse);
    expect(controller.isSubmittingReview, isFalse);
    // No re-fetch on failure — the fetch count stays at the initial one.
    expect(fake.fetchCount, 1);
  });

  testWidgets('submitReviewAPI on a generic failure does not re-fetch', (tester) async {
    final fake = _FakeVendorDetailDataSource()..reviewShouldFail = true;
    DataSource.instance = fake;

    final controller = VendorDetailController(
      vendorId: 5,
      subcategoryId: 10,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
    );
    await controller.fetchAPI();

    final succeeded = await controller.submitReviewAPI(rating: 3);

    expect(succeeded, isFalse);
    expect(fake.fetchCount, 1);
  });

  // ── Favorites / share / report vendor (SPEC 4 item 10) ────────────────

  testWidgets('toggleFavoriteAPI optimistically flips isFavorite then keeps it on success', (
    tester,
  ) async {
    final fake = _FakeVendorDetailDataSource();
    DataSource.instance = fake;

    final controller = VendorDetailController(
      vendorId: 5,
      subcategoryId: 10,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
    );
    await controller.fetchAPI();
    expect(controller.vendor?.isFavorite, isFalse);

    await controller.toggleFavoriteAPI();

    expect(controller.vendor?.isFavorite, isTrue);
    expect(fake.capturedToggleVendorIds, [5]);

    controller.onClose();
  });

  testWidgets('toggleFavoriteAPI reverts the optimistic flip on failure', (tester) async {
    final fake = _FakeVendorDetailDataSource()..toggleShouldFail = true;
    DataSource.instance = fake;

    final controller = VendorDetailController(
      vendorId: 5,
      subcategoryId: 10,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
    );
    await controller.fetchAPI();

    await controller.toggleFavoriteAPI();

    expect(controller.vendor?.isFavorite, isFalse);

    controller.onClose();
  });

  testWidgets('shareProfile calls the share seam with a text summary, no deep link', (
    tester,
  ) async {
    DataSource.instance = _FakeVendorDetailDataSource();
    final captured = <String>[];

    final controller = VendorDetailController(
      vendorId: 5,
      subcategoryId: 10,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
    )..shareFn = (text) async => captured.add(text);
    await controller.fetchAPI();

    await controller.shareProfile();

    expect(captured, hasLength(1));
    expect(captured.first, contains('Cool Air Services'));
    expect(captured.first, contains('12 MG Road'));
    expect(captured.first, contains('9812345678'));
    // No URL/deep link — see the controller's own docblock on why.
    expect(captured.first, isNot(contains('http')));

    controller.onClose();
  });

  testWidgets('reportVendorAPI submits the reason and reports success', (tester) async {
    final fake = _FakeVendorDetailDataSource();
    DataSource.instance = fake;

    final controller = VendorDetailController(
      vendorId: 5,
      subcategoryId: 10,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
    );
    await controller.fetchAPI();

    final succeeded = await controller.reportVendorAPI('Took an advance and never showed up.');

    expect(succeeded, isTrue);
    expect(controller.isSubmittingReport, isFalse);
    expect(fake.capturedReports, [
      {'vendorId': 5, 'reason': 'Took an advance and never showed up.'},
    ]);

    controller.onClose();
  });

  testWidgets('reportVendorAPI returns false on failure', (tester) async {
    final fake = _FakeVendorDetailDataSource()..reportShouldFail = true;
    DataSource.instance = fake;

    final controller = VendorDetailController(
      vendorId: 5,
      subcategoryId: 10,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
    );
    await controller.fetchAPI();

    final succeeded = await controller.reportVendorAPI('Some reason');

    expect(succeeded, isFalse);
    expect(controller.isSubmittingReport, isFalse);

    controller.onClose();
  });
}

class _ThrowingLeadDataSource extends _FakeVendorDetailDataSource {
  @override
  Future<CommonResponse?> createLeadAPI({
    required int vendorId,
    required int subcategoryId,
    int? zoneId,
    required String channel,
  }) async {
    throw Exception('network exploded');
  }
}
