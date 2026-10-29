import 'package:flutter_test/flutter_test.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/common_model/lead_model.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_leads_module/vendor_leads_controller.dart';

/// Fakes GET /vendors/me/leads (task 4.8) — two leads per page, two
/// pages total, plus a configurable POST .../request-review outcome.
class _FakeVendorLeadsDataSource extends DataSource {
  List<int> capturedPages = [];
  List<int> capturedRequestReviewLeadIds = [];
  bool requestReviewShouldFail = false;
  String requestReviewFailureMessage = 'This lead already has a review.';

  @override
  Future<CommonResponse?> vendorLeadsAPI({int page = 1, int perPage = 15}) async {
    capturedPages.add(page);

    final leadsByPage = {
      1: [_lead(1, 'Priya S.'), _lead(2, 'Rahul K.')],
      2: [_lead(3, 'Anjali M.')],
    };

    return CommonResponse.fromJson({
      'success': true,
      'data': leadsByPage[page] ?? [],
      'meta': {'current_page': page, 'per_page': 2, 'total': 3, 'last_page': 2},
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> requestReviewAPI({required int leadId}) async {
    capturedRequestReviewLeadIds.add(leadId);

    if (requestReviewShouldFail) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {'code': 'ALREADY_REVIEWED', 'message': requestReviewFailureMessage},
      });
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': {'id': leadId, 'review_requested_at': '2026-08-24T00:00:00Z'},
      'error': null,
    });
  }

  Map<String, dynamic> _lead(int id, String customerName) => {
        'id': id,
        'customer_name': customerName,
        'subcategory_name': 'Gas Filling',
        'zone_name': 'Gota',
        'channel': 'call',
        'created_at': '2026-08-20T00:00:00Z',
        'review_requested_at': null,
        'has_review': false,
      };
}

void main() {
  testWidgets('fetchAPI populates leads', (tester) async {
    final fake = _FakeVendorLeadsDataSource();
    DataSource.instance = fake;

    final controller = VendorLeadsController();
    await controller.fetchAPI();

    expect(controller.isLoading, isFalse);
    expect(controller.leads, hasLength(2));
    expect(controller.leads.first.customerName, 'Priya S.');
    expect(controller.hasMore, isTrue);
    expect(fake.capturedPages, [1]);

    controller.onClose();
  });

  testWidgets('load-more appends rather than replaces and requests the next page', (
    tester,
  ) async {
    final fake = _FakeVendorLeadsDataSource();
    DataSource.instance = fake;

    final controller = VendorLeadsController();
    await controller.fetchAPI();
    await controller.fetchAPI(loadMore: true);

    expect(controller.leads, hasLength(3));
    expect(controller.leads.map((l) => l.id), [1, 2, 3]);
    expect(controller.hasMore, isFalse);
    expect(fake.capturedPages, [1, 2]);

    controller.onClose();
  });

  testWidgets('requestReviewAPI updates the leads own reviewRequestedAt on success', (
    tester,
  ) async {
    final fake = _FakeVendorLeadsDataSource();
    DataSource.instance = fake;

    final controller = VendorLeadsController();
    await controller.fetchAPI();
    final lead = controller.leads.first;
    expect(lead.reviewRequestedAt, isNull);

    await controller.requestReviewAPI(lead);

    expect(lead.reviewRequestedAt, isNotNull);
    expect(fake.capturedRequestReviewLeadIds, [lead.id]);
    expect(controller.requestingReviewForLeadId, isNull);

    controller.onClose();
  });

  testWidgets('requestReviewAPI leaves reviewRequestedAt null on failure', (tester) async {
    final fake = _FakeVendorLeadsDataSource()..requestReviewShouldFail = true;
    DataSource.instance = fake;

    final controller = VendorLeadsController();
    await controller.fetchAPI();
    final lead = controller.leads.first;

    await controller.requestReviewAPI(lead);

    expect(lead.reviewRequestedAt, isNull);
    expect(controller.requestingReviewForLeadId, isNull);

    controller.onClose();
  });

  test('LeadModel.fromJson parses has_review and channel correctly', () {
    final lead = LeadModel.fromJson({
      'id': 5,
      'customer_name': 'Test',
      'subcategory_name': 'AC Repair',
      'zone_name': 'Gota',
      'channel': 'whatsapp',
      'created_at': '2026-08-20T00:00:00Z',
      'review_requested_at': null,
      'has_review': true,
    });

    expect(lead.hasReview, isTrue);
    expect(lead.channel, 'whatsapp');
  });
}
