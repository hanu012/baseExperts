import 'package:flutter_test/flutter_test.dart';
import 'package:service_marketplace/common_model/category_model.dart';
import 'package:service_marketplace/common_model/plan_model.dart';
import 'package:service_marketplace/common_model/zone_model.dart';
import 'package:service_marketplace/screens/vendor_flow/select_services_module/select_services_controller.dart';

/// Covers the selection cascade rules (SPEC section 2.2 step 2) that don't
/// come from the API response itself: subcategory <-> category linkage,
/// quota caps, and that a "select category" tap only ever cascades
/// subcategories up to the REMAINING quota rather than over it.
void main() {
  late CategoryModel acRepair;
  late SubcategoryModel gasFilling;
  late SubcategoryModel installation;
  late SubcategoryModel servicing;
  late ZoneModel ahmedabad;
  late ZoneModel gota;
  late ZoneModel sola;
  late ZoneModel rajkot;

  SelectServicesController buildController({
    int maxCategories = 2,
    int maxSubcategories = 2,
    int maxZones = 2,
  }) {
    gasFilling = SubcategoryModel(id: 101, categoryId: 1, name: 'Gas Filling');
    installation = SubcategoryModel(id: 102, categoryId: 1, name: 'Installation');
    servicing = SubcategoryModel(id: 103, categoryId: 1, name: 'Servicing');
    acRepair = CategoryModel(
      id: 1,
      name: 'AC Repair',
      subcategories: [gasFilling, installation, servicing],
    );

    gota = ZoneModel(id: 201, name: 'Gota', isLeaf: true);
    sola = ZoneModel(id: 202, name: 'Sola', isLeaf: true);
    ahmedabad = ZoneModel(id: 200, name: 'Ahmedabad', isLeaf: false, children: [gota, sola]);
    rajkot = ZoneModel(id: 210, name: 'Rajkot', isLeaf: true);

    final plan = PlanModel(
      id: 1,
      name: 'Gold',
      maxCategories: maxCategories,
      maxSubcategories: maxSubcategories,
      maxZones: maxZones,
    );

    final controller = SelectServicesController(
      vendorId: 1,
      plan: plan,
      businessName: 'Cool Air',
      loginEmail: 'vendor@example.com',
    );
    controller.categories = [acRepair];
    controller.zones = [ahmedabad, rajkot];

    return controller;
  }

  testWidgets('selecting a subcategory auto-selects its parent category', (tester) async {
    final controller = buildController();

    controller.toggleSubcategory(gasFilling, acRepair);

    expect(controller.isSubcategorySelected(gasFilling.id!), isTrue);
    expect(controller.isCategorySelected(acRepair.id!), isTrue);
  });

  testWidgets('unselecting a subcategory leaves the category selected', (tester) async {
    final controller = buildController();

    controller.toggleSubcategory(gasFilling, acRepair);
    controller.toggleSubcategory(installation, acRepair);
    controller.toggleSubcategory(gasFilling, acRepair); // un-select

    expect(controller.isSubcategorySelected(gasFilling.id!), isFalse);
    expect(controller.isSubcategorySelected(installation.id!), isTrue);
    expect(controller.isCategorySelected(acRepair.id!), isTrue);
  });

  testWidgets(
    'selecting a category cascades subcategories only up to the remaining quota',
    (tester) async {
      // maxSubcategories: 2, category has 3 subcategories.
      final controller = buildController(maxSubcategories: 2);

      controller.toggleCategory(acRepair);

      expect(controller.isCategorySelected(acRepair.id!), isTrue);
      expect(controller.selectedSubcategoryIds, {gasFilling.id, installation.id});
      expect(controller.isSubcategorySelected(servicing.id!), isFalse);
      // The counter is maxed, which is what the view uses to grey out the
      // skipped subcategory rather than leaving them silently unselected.
      expect(controller.subcategoryQuotaReached, isTrue);
    },
  );

  testWidgets('unselecting a category cascades to deselect all its subcategories', (
    tester,
  ) async {
    final controller = buildController(maxSubcategories: 3);

    controller.toggleCategory(acRepair);
    expect(controller.selectedSubcategoryIds, isNotEmpty);

    controller.toggleCategory(acRepair); // un-select

    expect(controller.isCategorySelected(acRepair.id!), isFalse);
    expect(controller.selectedSubcategoryIds, isEmpty);
  });

  testWidgets(
    'a subcategory tap is blocked once the category quota is reached and the category is not yet selected',
    (tester) async {
      final other = CategoryModel(id: 2, name: 'Plumbing', subcategories: []);
      final controller = buildController(maxCategories: 1);
      controller.categories = [acRepair, other];

      // Uses up the only category slot on something else entirely.
      controller.toggleCategory(other);
      expect(controller.categoryQuotaReached, isTrue);

      controller.toggleSubcategory(gasFilling, acRepair);

      expect(controller.isSubcategorySelected(gasFilling.id!), isFalse);
      expect(controller.isCategorySelected(acRepair.id!), isFalse);
    },
  );

  testWidgets('zone selection respects max_zones and toggles independently of parent', (
    tester,
  ) async {
    final controller = buildController(maxZones: 1);

    controller.toggleZone(gota);
    expect(controller.isZoneSelected(gota.id!), isTrue);
    expect(controller.zoneQuotaReached, isTrue);

    controller.toggleZone(sola);
    expect(controller.isZoneSelected(sola.id!), isFalse); // quota already used

    controller.toggleZone(gota); // un-select frees the slot
    controller.toggleZone(rajkot);
    expect(controller.isZoneSelected(rajkot.id!), isTrue);
  });

  testWidgets('a standalone top-level zone with no children is selectable like a leaf', (
    tester,
  ) async {
    expect(rajkot, isNotNull); // built in buildController, just documents intent
    final controller = buildController();

    controller.toggleZone(rajkot);

    expect(controller.isZoneSelected(rajkot.id!), isTrue);
  });

  testWidgets('validateSelections passes once every dimension has a pick', (
    tester,
  ) async {
    final controller = buildController();

    controller.toggleSubcategory(gasFilling, acRepair);
    controller.toggleZone(gota);

    expect(controller.validateSelections(), isTrue);
  });

  testWidgets('validateSelections fails when a dimension is empty', (tester) async {
    final controller = buildController();

    expect(controller.validateSelections(), isFalse);
  });
}
