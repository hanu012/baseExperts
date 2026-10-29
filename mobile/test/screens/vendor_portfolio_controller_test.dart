import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/common_model/portfolio_media_model.dart';
import 'package:service_marketplace/common_model/vendor_me_model.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_portfolio_module/vendor_portfolio_controller.dart';

/// Answers GET /api/vendors/me/portfolio and GET /api/vendors/me the way
/// task 4.5's real endpoints do — media list + quota, and the vendor's
/// currently selected subcategories (for the upload picker).
class _RecordingPortfolioDataSource extends DataSource {
  Map<String, dynamic>? capturedUploadFields;
  bool portfolioFails = false;
  bool uploadSucceeds = true;
  int photosUsed = 1;
  int photosMax = 3;
  int videosUsed = 0;
  int videosMax = 1;

  @override
  Future<CommonResponse?> vendorPortfolioAPI() async {
    if (portfolioFails) {
      return null;
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'media': [
          {
            'id': 1,
            'type': 'image',
            'url': 'https://example.com/a.jpg',
            'subcategory_id': 10,
            'subcategory_name': 'Gas Filling',
            'moderation_status': 'pending',
          },
        ],
        'quota': {
          'photos': {'used': photosUsed, 'max': photosMax},
          'videos': {'used': videosUsed, 'max': videosMax},
        },
      },
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> vendorMeAPI() async {
    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'vendor': {
          'id': 5,
          'business_name': 'Cool Air',
          'owner_name': 'Asha Patel',
          'phone': '9812345678',
          'email': 'vendor@example.com',
          'status': 'active',
          'has_active_subscription': true,
        },
        'active_subscription': {
          'plan_name': 'Gold',
          'end_date': '2027-01-01',
          'days_remaining': 100,
          'quota': {
            'categories': {'used': 1, 'max': 3},
            'subcategories': {'used': 1, 'max': 6},
            'zones': {'used': 1, 'max': 2},
          },
          'items': {
            'categories': [
              {'id': 1, 'name': 'AC Repair'},
            ],
            'subcategories': [
              {'id': 10, 'name': 'Gas Filling'},
            ],
            'zones': [
              {'id': 100, 'name': 'Gota'},
            ],
          },
        },
      },
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> uploadPortfolioMediaAPI({
    required String type,
    required int subcategoryId,
    required String filePath,
    ProgressCallback? onSendProgress,
  }) async {
    capturedUploadFields = {
      'type': type,
      'subcategory_id': subcategoryId,
      'file_path': filePath,
    };

    // Dio reports progress as the body goes out; replaying a couple of
    // ticks here keeps the fake honest about what the real call does.
    onSendProgress?.call(50, 100);
    onSendProgress?.call(100, 100);

    if (!uploadSucceeds) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {
          'code': 'VALIDATION_FAILED',
          'message': 'The given data was invalid.',
          'fields': {
            'file': ['This plan allows at most 3 photos — 0 remaining.'],
          },
        },
      }, statusCode: 422);
    }

    // Mutates the fake's own state so a follow-up fetchPortfolioAPI() (the
    // controller's post-upload refresh) sees the new count too, matching
    // how the real server's GET would.
    if (type == 'video') {
      videosUsed += 1;
    } else {
      photosUsed += 1;
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'media': {'id': 2, 'type': type, 'moderation_status': 'pending'},
        'quota': {
          'photos': {'used': photosUsed, 'max': photosMax},
          'videos': {'used': videosUsed, 'max': videosMax},
        },
      },
      'error': null,
    }, statusCode: 201);
  }
}

void main() {
  testWidgets('fetchPortfolioAPI populates media, quota, and subcategories', (tester) async {
    final fake = _RecordingPortfolioDataSource();
    DataSource.instance = fake;

    final controller = VendorPortfolioController();
    await controller.fetchPortfolioAPI();

    expect(controller.media, hasLength(1));
    expect(controller.media.first.subcategoryName, 'Gas Filling');
    expect(controller.photosQuota?.used, 1);
    expect(controller.photosQuota?.max, 3);
    expect(controller.videosQuota?.max, 1);
    expect(controller.subcategories, hasLength(1));
    expect(controller.subcategories.first.name, 'Gas Filling');
    // Defaults to the vendor's first offered subcategory.
    expect(controller.selectedSubcategoryId, 10);

    controller.onClose();
  });

  testWidgets('a failed portfolio fetch does not crash', (tester) async {
    final fake = _RecordingPortfolioDataSource()..portfolioFails = true;
    DataSource.instance = fake;

    final controller = VendorPortfolioController();
    await controller.fetchPortfolioAPI();

    expect(controller.media, isEmpty);

    controller.onClose();
  });

  testWidgets('uploadFile requires a subcategory to be selected', (tester) async {
    final fake = _RecordingPortfolioDataSource();
    DataSource.instance = fake;

    final controller = VendorPortfolioController();
    // No fetch — selectedSubcategoryId stays null.

    await controller.uploadFile(type: 'image', filePath: '/tmp/photo.jpg');

    expect(fake.capturedUploadFields, isNull);

    controller.onClose();
  });

  testWidgets('uploadFile rejects an oversized video locally, no network call', (tester) async {
    final fake = _RecordingPortfolioDataSource();
    DataSource.instance = fake;

    final controller = VendorPortfolioController();
    await controller.fetchPortfolioAPI();

    await controller.uploadFile(
      type: 'video',
      filePath: '/tmp/clip.mp4',
      sizeBytes: 60 * 1024 * 1024, // over the 50 MB cap
    );

    expect(fake.capturedUploadFields, isNull);

    controller.onClose();
  });

  testWidgets('uploadFile allows a video right at the cap', (tester) async {
    final fake = _RecordingPortfolioDataSource();
    DataSource.instance = fake;

    final controller = VendorPortfolioController();
    await controller.fetchPortfolioAPI();

    expect(controller.isVideoTooLarge(50 * 1024 * 1024), isFalse);
    expect(controller.isVideoTooLarge(50 * 1024 * 1024 + 1), isTrue);

    controller.onClose();
  });

  testWidgets('uploadFile sends the declared type, subcategory, and file path', (tester) async {
    final fake = _RecordingPortfolioDataSource();
    DataSource.instance = fake;

    final controller = VendorPortfolioController();
    await controller.fetchPortfolioAPI();

    await tester.pumpWidget(GetMaterialApp(home: const Scaffold(body: SizedBox())));
    await controller.uploadFile(type: 'image', filePath: '/tmp/photo.jpg');

    expect(fake.capturedUploadFields?['type'], 'image');
    expect(fake.capturedUploadFields?['subcategory_id'], 10);
    expect(fake.capturedUploadFields?['file_path'], '/tmp/photo.jpg');
    expect(controller.isUploading, isFalse);
    // Refetched after success — quota now reflects the new upload.
    expect(controller.photosQuota?.used, 2);

    controller.onClose();
  });

  testWidgets('groups services under their category and counts live work', (tester) async {
    final controller = VendorPortfolioController()
      ..categories = [SelectedServiceItemModel(id: 1, name: 'AC Repair')]
      ..subcategories = [
        SelectedServiceItemModel(id: 10, name: 'Gas Filling', categoryId: 1),
        SelectedServiceItemModel(id: 11, name: 'Installation', categoryId: 1),
        // Its category is not in the plan's list — must still be reachable.
        SelectedServiceItemModel(id: 20, name: 'Wiring', categoryId: 99),
      ]
      ..media = [
        PortfolioMediaModel(id: 1, type: 'image', url: 'a.jpg', subcategoryId: 10, moderationStatus: 'approved'),
        PortfolioMediaModel(id: 2, type: 'video', url: 'b.mp4', subcategoryId: 10, moderationStatus: 'approved'),
        // Taken down by an admin: not counted.
        PortfolioMediaModel(id: 3, type: 'image', url: 'c.jpg', subcategoryId: 10, moderationStatus: 'rejected'),
      ];

    expect(controller.subcategoriesOf(1).map((s) => s.id), [10, 11]);
    expect(controller.subcategoriesOf(VendorPortfolioController.otherCategoryId).map((s) => s.id), [20]);
    expect(controller.portfolioCategories.map((c) => c.id), [1, VendorPortfolioController.otherCategoryId]);

    expect(controller.photoCount(10), 1);
    expect(controller.videoCount(10), 1);
    expect(controller.categoryPhotoCount(1), 1);
    expect(controller.coverFor(10)?.id, 1);
    expect(controller.coverFor(11), isNull);

    controller.selectSubcategory(10);
    expect(controller.selectedCategoryName, 'AC Repair');
    expect(controller.visibleMedia, hasLength(3));

    controller.onClose();
  });

  testWidgets('a server-side rejection resets isUploading without crashing', (tester) async {
    final fake = _RecordingPortfolioDataSource()..uploadSucceeds = false;
    DataSource.instance = fake;

    final controller = VendorPortfolioController();
    await controller.fetchPortfolioAPI();

    await tester.pumpWidget(GetMaterialApp(home: const Scaffold(body: SizedBox())));
    await controller.uploadFile(type: 'image', filePath: '/tmp/photo.jpg');

    expect(fake.capturedUploadFields, isNotNull);
    expect(controller.isUploading, isFalse);

    controller.onClose();
  });
}
