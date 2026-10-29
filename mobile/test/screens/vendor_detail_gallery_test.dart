import 'package:flutter_test/flutter_test.dart';
import 'package:service_marketplace/common_model/vendor_detail_model.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_detail_module/vendor_detail_controller.dart';

/// The customer-side work gallery: filter chips per service, and the
/// media the grid shows for each.
void main() {
  VendorDetailController controllerWith(VendorDetailModel vendor) {
    return VendorDetailController(
      vendorId: 5,
      subcategoryId: 10,
      zoneId: null,
      latitude: null,
      longitude: null,
    )..vendor = vendor;
  }

  final vendor = VendorDetailModel.fromJson({
    'id': 5,
    'business_name': 'Cool Air',
    'services': [
      {'subcategory_id': 10, 'name': 'Gas Filling', 'category_id': 1, 'category_name': 'AC Repair'},
      {'subcategory_id': 11, 'name': 'Installation', 'category_id': 1, 'category_name': 'AC Repair'},
      {'subcategory_id': 12, 'name': 'Servicing', 'category_id': 1, 'category_name': 'AC Repair'},
    ],
    'media': [
      {'id': 1, 'type': 'image', 'url': 'a.jpg', 'subcategory_id': 10, 'subcategory_name': 'Gas Filling', 'category_name': 'AC Repair'},
      {'id': 2, 'type': 'video', 'url': 'b.mp4', 'subcategory_id': 10, 'subcategory_name': 'Gas Filling', 'category_name': 'AC Repair'},
      {'id': 3, 'type': 'image', 'url': 'c.jpg', 'subcategory_id': 11, 'subcategory_name': 'Installation', 'category_name': 'AC Repair'},
    ],
  });

  test('chips list only services that have work, in service order', () {
    final controller = controllerWith(vendor);

    expect(controller.galleryServices.map((s) => s.subcategoryId), [10, 11]);
    expect(controller.galleryCount(10), 2);
    expect(controller.galleryCount(12), 0);
  });

  test('All shows everything; a chip filters to its service', () {
    final controller = controllerWith(vendor);

    expect(controller.galleryMedia, hasLength(3));

    controller.selectGalleryService(11);
    expect(controller.galleryMedia.map((m) => m.id), [3]);

    controller.selectGalleryService(null);
    expect(controller.galleryMedia, hasLength(3));
  });

  test('each item names its service for the viewer caption', () {
    expect(vendor.media.first.serviceLabel, 'AC Repair › Gas Filling');
  });
}
