import 'dart:async';

import 'package:easy_localization/easy_localization.dart';

import 'constants/app.export.dart';
import 'constants/constant.dart';
import 'constants/flavor_config.dart';
import 'screens/auth_flow/splash_module/splash_view.dart';
import 'utils/call_tracker.dart';
import 'utils/socket_service.dart';

/// Shared bootstrap for all three flavours.
///
/// Each `main_*.dart` resolves its [FlavorConfig] and calls this; everything
/// after that point is identical, so flavour drift is impossible by
/// construction.
Future<void> bootstrap(FlavorConfig config) async {
  WidgetsFlutterBinding.ensureInitialized();

  FlavorConfig.initialize(config);

  await EasyLocalization.ensureInitialized();
  await Injector.initialize();
  SocketService.init();

  if (kDebugMode) {
    print('Booting flavour: ${config.flavor.name} (${config.appName})');
  }

  runApp(
    EasyLocalization(
      supportedLocales: [
        for (final code in Constants.languages.keys) Locale(code),
      ],
      path: 'assets/translations',
      fallbackLocale: const Locale(Constants.english),
      // Injector owns the saved choice (PrefKeys.language), so the
      // package's own copy is turned off rather than kept in sync.
      saveLocale: false,
      startLocale: Locale(
        Constants.languages.containsKey(Injector.language)
            ? Injector.language
            : Constants.english,
      ),
      child: const ServiceMarketplaceApp(),
    ),
  );

  // Not awaited: the app opens in the saved language straight away.
  unawaited(Utils.enforceEnabledLanguage());
  // Admin app switches (call tracking) — cached, refreshed in the background.
  unawaited(Utils.refreshAppSettings());
  // Live events (Laravel Reverb). Signed-out launches connect after login,
  // from the screens that need it — connect() is safe to call repeatedly.
  if (Injector.isLoggedIn) {
    unawaited(SocketService.instance.connect());
  }
  // Android exact call length: sends any call the customer never came
  // back from, and watches for the next return to the app.
  if (config.isCustomer) {
    CallTracker.init();
  }
}

class ServiceMarketplaceApp extends StatelessWidget {
  const ServiceMarketplaceApp({super.key});

  @override
  Widget build(BuildContext context) {
    final config = FlavorConfig.current;

    return GetMaterialApp(
      title: config.appName,
      debugShowCheckedModeBanner: false,

      // Utils.key backs Utils.getSize, which needs a mounted navigator to
      // read the screen width.
      navigatorKey: Utils.key,

      localizationsDelegates: context.localizationDelegates,
      supportedLocales: context.supportedLocales,
      locale: context.locale,

      // Dark only, matching CLAUDE.md's Theme section and the admin panel.
      themeMode: ThemeMode.dark,
      theme: _theme,
      darkTheme: _theme,

      // A corner ribbon naming the running flavour — the three apps look
      // alike on a dev device otherwise. Debug builds only; never shipped.
      builder: (context, child) {
        // Keeps every route clear of the system navigation bar.
        //
        // Android 15+ draws apps edge-to-edge whether they ask or not, so
        // anything pinned to the bottom of a screen — action buttons,
        // bottom sheets, the last item of a list — sat under the gesture
        // pill or the 3-button bar on many devices. Applied once here,
        // above the Navigator, it covers every screen, dialog and sheet,
        // including ones added later; widgets that already pad themselves
        // (CustomerBottomNav, VendorBottomNav) see that padding consumed and
        // do not double it.
        //
        // Bottom (and sides) only: the top is owned per screen, so the teal
        // headers can still run up behind the status bar — they and the
        // AppBars already pad for it themselves.
        Widget content = ColoredBox(
          color: ServiceTokens.card,
          child: SafeArea(
            top: false,
            child: child ?? const SizedBox.shrink(),
          ),
        );

        if (!kDebugMode) return content;

        // The phone bezel/notch/frame around this (when running on web)
        // lives OUTSIDE the app now — a wrapper HTML page embeds the
        // dev-server build in a real phone-dimensioned iframe. That gives
        // GetX's Get.width/Get.height (which `.getSize` etc. scale off,
        // and which read the actual rendering surface, not any in-app
        // MediaQuery override — see mobile/device_preview/README.md) the
        // real phone width to scale against, instead of faking a frame
        // in-app and fighting that scaling. See mobile/device_preview/.
        return Banner(
          message: config.flavor.name.toUpperCase(),
          location: BannerLocation.topStart,
          color: ColorRes.primaryColor,
          textStyle: TextStyle(
            color: ColorRes.backgroundColor,
            fontSize: 10,
            fontWeight: FontWeight.w600,
            letterSpacing: 0.5,
          ),
          child: content,
        );
      },

      // The splash decides the real entry screen (login, home, or one of
      // the two account gates) — see SplashController.entryScreen().
      home: const SplashView(),
    );
  }

  ThemeData get _theme => ThemeData(
        useMaterial3: true,
        brightness: Brightness.light,
        scaffoldBackgroundColor: ColorRes.backgroundColor,
        fontFamily: FontFamily.poppins,
        colorScheme: ColorScheme.light(
          primary: ColorRes.primaryColor,
          // White, not the canvas: the canvas is now near-white itself,
          // so using it here would put white-on-white inside any widget
          // that paints onPrimary over the primary fill.
          onPrimary: ColorRes.whiteColor,
          surface: ColorRes.surfaceColor,
          onSurface: ColorRes.secondaryColor,
          error: ColorRes.errorColor,
        ),
        appBarTheme: AppBarTheme(
          backgroundColor: ColorRes.surfaceColor,
          foregroundColor: ColorRes.secondaryColor,
          surfaceTintColor: ColorRes.transparent,
          elevation: 0,
        ),
      );
}
