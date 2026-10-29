import 'package:flutter_test/flutter_test.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_dashboard_module/vendor_dashboard_controller.dart';
import 'package:service_marketplace/screens/vendor_flow/vendor_documents_module/vendor_documents_controller.dart';

/// GET /vendors/me/documents with whatever statuses a test needs.
class _DocumentsDataSource extends DataSource {
  _DocumentsDataSource(this.documents, {this.missing = const []});

  final List<Map<String, dynamic>> documents;
  final List<String> missing;

  @override
  Future<CommonResponse?> vendorDocumentsAPI({int? vendorId}) async => CommonResponse.fromJson({
        'success': true,
        'data': {
          'documents': documents,
          'missing_compulsory': missing,
          'complete': missing.isEmpty,
        },
        'error': null,
      });
}

Map<String, dynamic> _document(int typeId, String name, String status) => {
      'id': status == 'pending_upload' ? null : typeId,
      'document_type': {
        'id': typeId,
        'code': name.toLowerCase(),
        'name': name,
        'description': null,
        'has_number': false,
        'number_label': null,
        'number_hint': null,
        'mask_number': false,
        'has_expiry': false,
        'has_back': false,
        'is_compulsory': true,
      },
      'status': status,
      'number': null,
      'expiry_date': null,
      'front_url': null,
      'back_url': null,
      'review_note': status == 'reupload' ? 'Blurred' : null,
      'uploaded_at': null,
      'can_upload': status != 'verified',
    };

void main() {
  group('home notice for an approved vendor', () {
    testWidgets('lists documents sent back, expired, or compulsory and missing — once each', (tester) async {
      DataSource.instance = _DocumentsDataSource(
        [
          _document(1, 'Aadhaar Card', 'verified'),
          _document(2, 'PAN Card', 'reupload'),
          _document(3, 'Driving Licence', 'expired'),
        ],
        // PAN also counts as missing: listed once.
        missing: ['PAN Card', 'GST Certificate'],
      );

      final controller = VendorDashboardController();
      await controller.fetchDocumentsAttentionAPI();

      expect(controller.documentsNeedingAttention, ['PAN Card', 'Driving Licence', 'GST Certificate']);
    });

    testWidgets('all verified: no notice', (tester) async {
      DataSource.instance = _DocumentsDataSource([
        _document(1, 'Aadhaar Card', 'verified'),
        _document(2, 'PAN Card', 'in_verification'),
      ]);

      final controller = VendorDashboardController();
      await controller.fetchDocumentsAttentionAPI();

      expect(controller.documentsNeedingAttention, isEmpty);
    });
  });

  test('a signed link is recognised as a PDF by its path, not its query string', () {
    expect(VendorDocumentsController.isPdf('https://x.test/storage/vendor-kyc/1/a.pdf?expires=1&signature=abc'), isTrue);
    expect(VendorDocumentsController.isPdf('https://x.test/storage/vendor-kyc/1/a.JPG?expires=1'), isFalse);
  });
}
