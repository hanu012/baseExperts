import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/common_model/plan_model.dart';
import 'package:service_marketplace/constants/flavor_config.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_select_services_module/vendor_select_services_controller.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_select_services_module/vendor_select_services_view.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_select_services_module/vendor_select_zones_view.dart';
import 'package:service_marketplace/screens/vendor_flow/select_services_module/select_services_controller.dart';
import 'package:service_marketplace/screens/vendor_flow/select_services_module/select_services_view.dart';
import 'package:service_marketplace/screens/vendor_flow/select_services_module/select_zones_view.dart';
import 'package:service_marketplace/widgets/base_services.dart';

/// Layout guards for the two service/zone selection screens.
///
/// These exist because the failure mode here is quiet: a layout assertion in
/// this chrome renders an empty body under a perfectly healthy-looking
/// header, which reads as "the API returned nothing" rather than as a crash.
/// Pinning the header above an `Expanded` did exactly that — on a short
/// viewport the header plus the bottom action exceeded the height, the
/// Expanded collapsed to zero, and the page went blank. `takeException()` is
/// what turns that back into a loud failure.
class _FakeMasterDataSource extends DataSource {
  _FakeMasterDataSource({this.categories = const [], this.zones = const []});

  final List<Map<String, dynamic>> categories;
  final List<Map<String, dynamic>> zones;

  @override
  Future<CommonResponse?> categoriesAPI() async =>
      CommonResponse.fromJson({'success': true, 'data': categories, 'error': null});

  @override
  Future<CommonResponse?> zonesAPI() async =>
      CommonResponse.fromJson({'success': true, 'data': zones, 'error': null});

  @override
  Future<CommonResponse?> settingsAPI() async => CommonResponse.fromJson({
        'success': true,
        'data': {'free_trial_max_days': 15},
        'error': null,
      });
}

void main() {
  /// iPhone SE (1st gen) in logical pixels — the smallest realistic screen.
  const smallPhone = Size(320, 568);

  final plan = PlanModel(
    id: 1,
    name: 'Pro',
    maxCategories: 10,
    maxSubcategories: 40,
    maxZones: 15,
  );

  final categories = [
    {
      'id': 1,
      'name': 'AC Service',
      'subcategories': [
        {'id': 11, 'category_id': 1, 'name': 'AC Installation'},
        {'id': 12, 'category_id': 1, 'name': 'AC Gas Filling'},
      ],
    },
    {
      'id': 2,
      'name': 'Plumbing',
      'subcategories': [
        {'id': 21, 'category_id': 2, 'name': 'Leak Repair'},
      ],
    },
  ];

  final zones = [
    {
      'id': 100,
      'name': 'Ahmedabad',
      'is_leaf': false,
      'children': [
        {'id': 101, 'name': 'Bodakdev', 'is_leaf': true},
        {'id': 102, 'name': 'Bopal', 'is_leaf': true},
      ],
    },
  ];

  void useSurface(WidgetTester tester, {double bottomInset = 0}) {
    tester.view.devicePixelRatio = 1.0;
    tester.view.physicalSize = smallPhone;
    tester.view.viewInsets = FakeViewPadding(bottom: bottomInset);

    addTearDown(() {
      tester.view.resetDevicePixelRatio();
      tester.view.resetPhysicalSize();
      tester.view.resetViewInsets();
      Get.reset();
    });
  }

  Map<String, (FlavorConfig, Widget)> screens() => {
        'SelectServicesView': (
          FlavorConfig.salesman,
          SelectServicesView(
            vendorId: 1,
            plan: plan,
            businessName: 'Test Business',
            loginEmail: 'vendor@example.com',
          ),
        ),
        'VendorSelectServicesView': (
          FlavorConfig.vendor,
          VendorSelectServicesView(vendorId: 1, plan: plan),
        ),
      };

  group('service selection screens lay out without exceptions', () {
    for (final entry in screens().entries) {
      testWidgets('${entry.key} with a full catalogue on a 320x568 screen',
          (tester) async {
        FlavorConfig.initialize(entry.value.$1);
        DataSource.instance =
            _FakeMasterDataSource(categories: categories, zones: zones);
        useSurface(tester);

        await tester.pumpWidget(GetMaterialApp(home: entry.value.$2));
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 50));

        expect(tester.takeException(), isNull);
      });

      testWidgets('${entry.key} with the keyboard open', (tester) async {
        FlavorConfig.initialize(entry.value.$1);
        DataSource.instance =
            _FakeMasterDataSource(categories: categories, zones: zones);
        useSurface(tester, bottomInset: 300);

        await tester.pumpWidget(GetMaterialApp(home: entry.value.$2));
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 50));

        expect(tester.takeException(), isNull);
      });

      testWidgets('${entry.key} still renders its chrome when the catalogue is empty',
          (tester) async {
        FlavorConfig.initialize(entry.value.$1);
        DataSource.instance = _FakeMasterDataSource();
        useSurface(tester);

        await tester.pumpWidget(GetMaterialApp(home: entry.value.$2));
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 50));

        expect(tester.takeException(), isNull);

        // No data is not the same as no page: the header, the search field,
        // the counters and the action all render from local state alone. A
        // blank body here is the bug this file was written for.
        expect(find.byType(ServicesHeader), findsOneWidget);
        expect(find.byType(ServicesSearchBar), findsOneWidget);
        expect(find.byType(ServicesStatTile), findsWidgets);
        expect(find.byType(ServicesFooter), findsOneWidget);
      });

      testWidgets('${entry.key} renders a card per category and per city',
          (tester) async {
        FlavorConfig.initialize(entry.value.$1);
        DataSource.instance =
            _FakeMasterDataSource(categories: categories, zones: zones);
        useSurface(tester);

        await tester.pumpWidget(GetMaterialApp(home: entry.value.$2));
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 50));

        // Two categories; zones live on the next step now.
        expect(find.byType(ServicesGroupCard), findsNWidgets(2));
      });
    }
  });

  group('service selection screens share one design system', () {
    for (final entry in screens().entries) {
      testWidgets('${entry.key} is built from the shared chrome', (tester) async {
        FlavorConfig.initialize(entry.value.$1);
        DataSource.instance =
            _FakeMasterDataSource(categories: categories, zones: zones);
        useSurface(tester);

        await tester.pumpWidget(GetMaterialApp(home: entry.value.$2));
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 50));

        // If one screen forks off the shared widgets, the two flows have
        // silently diverged — invisible until someone compares screenshots.
        expect(find.byType(ServicesHeader), findsOneWidget);
        expect(find.byType(ServicesPage), findsOneWidget);
        expect(find.byType(ServicesFooter), findsOneWidget);
      });
    }
  });

  group('the flow is two steps, services then zones', () {
    testWidgets('Continue moves the salesman from services to coverage zones',
        (tester) async {
      FlavorConfig.initialize(FlavorConfig.salesman);
      DataSource.instance =
          _FakeMasterDataSource(categories: categories, zones: zones);
      useSurface(tester);

      await tester.pumpWidget(GetMaterialApp(
        home: SelectServicesView(
          vendorId: 1,
          plan: plan,
          businessName: 'Test Business',
          loginEmail: 'vendor@example.com',
        ),
      ));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 50));

      // Zones are their own step now, not a section of this one.
      expect(find.byType(SelectZonesView), findsNothing);

      // Nothing picked, so Continue must refuse rather than advance — the
      // subscribe call needs at least one subcategory.
      await tester.tap(find.byType(ServicesFooter));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
      expect(find.byType(SelectZonesView), findsNothing);

      // Pick a category (which cascades its subcategories), then advance.
      final controller = Get.find<SelectServicesController>();
      controller.toggleCategory(controller.categories.first);
      await tester.pump();

      await tester.tap(find.byType(ServicesFooter));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));

      expect(find.byType(SelectZonesView), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('the zones step reuses the services step controller',
        (tester) async {
      FlavorConfig.initialize(FlavorConfig.vendor);
      DataSource.instance =
          _FakeMasterDataSource(categories: categories, zones: zones);
      useSurface(tester);

      await tester.pumpWidget(GetMaterialApp(
        home: VendorSelectServicesView(vendorId: 1, plan: plan),
      ));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 50));

      final controller = Get.find<VendorSelectServicesController>();
      controller.toggleCategory(controller.categories.first);
      await tester.pump();

      await tester.tap(find.byType(ServicesFooter));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));

      expect(find.byType(VendorSelectZonesView), findsOneWidget);

      // Same instance, so the services picks survived the hop. If the zones
      // step built its own controller they would be gone, and the subscribe
      // call would ship an empty subcategory list.
      expect(Get.find<VendorSelectServicesController>(), same(controller));
      expect(controller.selectedSubcategoryIds, isNotEmpty);
      expect(tester.takeException(), isNull);
    });

    // The zones step used to delete the shared controller on its way out,
    // while the services step was still open beneath it — so going Back and
    // pressing Continue again crashed with "controller not found".
    for (final entry in screens().entries) {
      testWidgets('${entry.key}: Back from zones then Continue again works',
          (tester) async {
        FlavorConfig.initialize(entry.value.$1);
        DataSource.instance =
            _FakeMasterDataSource(categories: categories, zones: zones);
        useSurface(tester);

        await tester.pumpWidget(GetMaterialApp(home: entry.value.$2));
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 50));

        final isSalesman = entry.key == 'SelectServicesView';
        if (isSalesman) {
          final c = Get.find<SelectServicesController>();
          c.toggleCategory(c.categories.first);
        } else {
          final c = Get.find<VendorSelectServicesController>();
          c.toggleCategory(c.categories.first);
        }
        await tester.pump();

        Future<void> continueToZones() async {
          await tester.tap(find.byType(ServicesFooter));
          await tester.pump();
          await tester.pump(const Duration(milliseconds: 400));
        }

        final zonesStep = isSalesman ? SelectZonesView : VendorSelectZonesView;

        await continueToZones();
        expect(find.byType(zonesStep), findsOneWidget);

        Get.back();
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 400));
        expect(find.byType(zonesStep), findsNothing);

        await continueToZones();
        expect(find.byType(zonesStep), findsOneWidget);
        expect(tester.takeException(), isNull);
      });
    }
  });
}
