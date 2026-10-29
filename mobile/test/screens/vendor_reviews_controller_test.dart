import 'package:flutter_test/flutter_test.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_reviews_module/vendor_reviews_controller.dart';

/// Fakes GET /vendors/me/reviews + POST .../reply (task 4.8).
class _FakeVendorReviewsDataSource extends DataSource {
  List<int> capturedPages = [];
  List<Map<String, dynamic>> capturedReplies = [];
  bool replyShouldFail = false;

  @override
  Future<CommonResponse?> vendorReviewsAPI({int page = 1, int perPage = 15}) async {
    capturedPages.add(page);

    final reviewsByPage = {
      1: [_review(1, isHidden: false), _review(2, isHidden: true)],
      2: [_review(3, isHidden: false)],
    };

    return CommonResponse.fromJson({
      'success': true,
      'data': reviewsByPage[page] ?? [],
      'meta': {'current_page': page, 'per_page': 2, 'total': 3, 'last_page': 2},
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> replyToReviewAPI({required int reviewId, required String reply}) async {
    capturedReplies.add({'reviewId': reviewId, 'reply': reply});

    if (replyShouldFail) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {'code': 'NOT_FOUND', 'message': 'Review not found.'},
      });
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'id': reviewId,
        'rating': 4,
        'comment': 'Good work',
        'customer_name': 'Priya S.',
        'vendor_reply': reply,
        'replied_at': '2026-08-24T00:00:00Z',
        'created_at': '2026-08-20T00:00:00Z',
      },
      'error': null,
    });
  }

  Map<String, dynamic> _review(int id, {required bool isHidden}) => {
        'id': id,
        'rating': 4,
        'comment': 'Good work',
        'customer_name': 'Priya S.',
        'vendor_reply': null,
        'replied_at': null,
        'is_hidden': isHidden,
        'created_at': '2026-08-20T00:00:00Z',
      };
}

void main() {
  testWidgets('fetchAPI populates reviews including isHidden', (tester) async {
    final fake = _FakeVendorReviewsDataSource();
    DataSource.instance = fake;

    final controller = VendorReviewsController();
    await controller.fetchAPI();

    expect(controller.isLoading, isFalse);
    expect(controller.reviews, hasLength(2));
    expect(controller.reviews.first.isHidden, isFalse);
    expect(controller.reviews.last.isHidden, isTrue);
    expect(controller.hasMore, isTrue);
    expect(fake.capturedPages, [1]);

    controller.onClose();
  });

  testWidgets('load-more appends rather than replaces and requests the next page', (
    tester,
  ) async {
    final fake = _FakeVendorReviewsDataSource();
    DataSource.instance = fake;

    final controller = VendorReviewsController();
    await controller.fetchAPI();
    await controller.fetchAPI(loadMore: true);

    expect(controller.reviews, hasLength(3));
    expect(controller.hasMore, isFalse);
    expect(fake.capturedPages, [1, 2]);

    controller.onClose();
  });

  testWidgets('replyToReviewAPI patches the reviews own fields on success', (tester) async {
    final fake = _FakeVendorReviewsDataSource();
    DataSource.instance = fake;

    final controller = VendorReviewsController();
    await controller.fetchAPI();
    final review = controller.reviews.first;
    expect(review.vendorReply, isNull);

    final succeeded = await controller.replyToReviewAPI(review, 'Thank you!');

    expect(succeeded, isTrue);
    expect(review.vendorReply, 'Thank you!');
    expect(review.repliedAt, isNotNull);
    expect(fake.capturedReplies, [
      {'reviewId': review.id, 'reply': 'Thank you!'},
    ]);
    expect(controller.isSubmittingReply, isFalse);

    controller.onClose();
  });

  testWidgets('replyToReviewAPI leaves the review unchanged on failure', (tester) async {
    final fake = _FakeVendorReviewsDataSource()..replyShouldFail = true;
    DataSource.instance = fake;

    final controller = VendorReviewsController();
    await controller.fetchAPI();
    final review = controller.reviews.first;

    final succeeded = await controller.replyToReviewAPI(review, 'Thank you!');

    expect(succeeded, isFalse);
    expect(review.vendorReply, isNull);
    expect(controller.isSubmittingReply, isFalse);

    controller.onClose();
  });
}
