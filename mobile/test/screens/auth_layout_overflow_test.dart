import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/constants/flavor_config.dart';
import 'package:service_marketplace/screens/auth_flow/check_email_module/check_email_view.dart';
import 'package:service_marketplace/screens/auth_flow/customer_login_module/customer_login_view.dart';
import 'package:service_marketplace/screens/auth_flow/customer_register_module/customer_register_view.dart';
import 'package:service_marketplace/screens/auth_flow/salesman_login_module/salesman_login_view.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_login_module/vendor_login_view.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_register_module/vendor_register_view.dart';
import 'package:service_marketplace/widgets/base_auth.dart';

/// Layout guards for the redesigned auth screens.
///
/// A RenderFlex overflow surfaces as an exception in a widget test, so
/// `takeException()` returning null is a real assertion here, not a formality
/// — the previous `Utils.authLayout` chrome overflowed by 89px at these
/// dimensions, which is what prompted the redesign.
///
/// The sizes below are deliberately hostile: 320x568 is the iPhone SE (1st
/// gen), the smallest screen this app can realistically meet, and the keyboard
/// case leaves only 268pt of usable height.
void main() {
  /// iPhone SE (1st gen) in logical pixels.
  const smallPhone = Size(320, 568);

  /// What a keyboard leaves behind on that phone.
  const keyboardInset = 300.0;

  /// Every screen under test, with the flavour it belongs to — the register
  /// controllers read `FlavorConfig.current.role` when they submit, and
  /// `FlavorConfig.current` is a `late` field that throws if never assigned.
  final screens = <String, (FlavorConfig, Widget)>{
    'SalesmanLoginView': (FlavorConfig.salesman, const SalesmanLoginView()),
    'VendorLoginView': (FlavorConfig.vendor, const VendorLoginView()),
    'VendorRegisterView': (FlavorConfig.vendor, const VendorRegisterView()),
    'CustomerLoginView': (FlavorConfig.customer, const CustomerLoginView()),
    'CustomerRegisterView': (FlavorConfig.customer, const CustomerRegisterView()),
  };

  /// Sizes the test surface, and restores it afterwards so one test cannot
  /// leak its dimensions into the next.
  void useSurface(WidgetTester tester, {double bottomInset = 0}) {
    tester.view.devicePixelRatio = 1.0;
    tester.view.physicalSize = smallPhone;
    tester.view.viewInsets = FakeViewPadding(bottom: bottomInset);

    addTearDown(() {
      tester.view.resetDevicePixelRatio();
      tester.view.resetPhysicalSize();
      tester.view.resetViewInsets();
    });
  }

  group('auth screens lay out without overflow', () {
    for (final entry in screens.entries) {
      testWidgets('${entry.key} on a 320x568 screen', (tester) async {
        FlavorConfig.initialize(entry.value.$1);
        useSurface(tester);

        await tester.pumpWidget(GetMaterialApp(home: entry.value.$2));
        await tester.pump();

        expect(tester.takeException(), isNull);
      });

      testWidgets('${entry.key} with the keyboard open', (tester) async {
        FlavorConfig.initialize(entry.value.$1);
        useSurface(tester, bottomInset: keyboardInset);

        await tester.pumpWidget(GetMaterialApp(home: entry.value.$2));
        await tester.pump();

        expect(tester.takeException(), isNull);

        // The form must still be reachable rather than merely un-crashed:
        // a scrollable is what turns "taller than the viewport" into
        // "scroll to it" instead of a clipped, unusable field.
        expect(find.byType(Scrollable), findsWidgets);
      });
    }
  });

  group('auth screens share one design system', () {
    for (final entry in screens.entries) {
      testWidgets('${entry.key} is built from AuthScaffold', (tester) async {
        FlavorConfig.initialize(entry.value.$1);
        useSurface(tester);

        await tester.pumpWidget(GetMaterialApp(home: entry.value.$2));
        await tester.pump();

        // If a screen ever stops using the shared chrome, it has forked the
        // design system — which is the exact failure this file exists to
        // catch, since a fork is invisible until someone compares screenshots.
        expect(find.byType(AuthScaffold), findsOneWidget);
        expect(find.byType(AuthPrimaryButton), findsOneWidget);
      });
    }
  });

  testWidgets('sign-up asks for no password; sign-in keeps its toggle', (tester) async {
    FlavorConfig.initialize(FlavorConfig.vendor);
    useSurface(tester);

    // The password is set from the emailed link, not on this form.
    await tester.pumpWidget(const GetMaterialApp(home: VendorRegisterView()));
    await tester.pump();

    expect(find.byType(AuthVisibilityToggle), findsNothing);

    await tester.pumpWidget(const GetMaterialApp(home: VendorLoginView()));
    await tester.pump();

    expect(find.byType(AuthVisibilityToggle), findsOneWidget);
  });

  /// Built like the forced password change (a gradient hero over a plain
  /// column), not AuthScaffold — it has no form — so it is checked here
  /// rather than in the shared-chrome group above.
  for (final bottomInset in [0.0, keyboardInset]) {
    testWidgets('CheckEmailView lays out without overflow (inset $bottomInset)',
        (tester) async {
      FlavorConfig.initialize(FlavorConfig.vendor);
      useSurface(tester, bottomInset: bottomInset);

      await tester.pumpWidget(const GetMaterialApp(
        home: CheckEmailView(
          email: 'asha.patel.longname@example-business.com',
          maskedEmail: 'a***@example-business.com',
        ),
      ));
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.byType(AuthPrimaryButton), findsOneWidget);
    });
  }

  testWidgets('login screens show no back chip, register screens do',
      (tester) async {
    FlavorConfig.initialize(FlavorConfig.vendor);
    useSurface(tester);

    await tester.pumpWidget(const GetMaterialApp(home: VendorLoginView()));
    await tester.pump();

    // Nothing behind a flavour's first screen to go back to.
    expect(find.byType(AuthBackChip), findsNothing);

    await tester.pumpWidget(const GetMaterialApp(home: VendorRegisterView()));
    await tester.pump();

    expect(find.byType(AuthBackChip), findsOneWidget);
  });
}
