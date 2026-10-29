import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'customer_current_location_controller.dart';

/// "Current location" (SPEC section 4.2) — the GPS target and its
/// concentric rings, the detected place, and the confirm action.
class CustomerCurrentLocationView extends StatelessWidget {
  const CustomerCurrentLocationView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<CustomerCurrentLocationController>(
      init: CustomerCurrentLocationController(),
      dispose: (_) => Get.delete<CustomerCurrentLocationController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          body: SafeArea(
            child: Column(
              children: [
                header(),
                Expanded(child: content(controller)),
                footer(controller),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget header() {
    return Padding(
      padding: EdgeInsets.fromLTRB(16.getSize, 10.getSize, 16.getSize, 0),
      child: Row(
        children: [
          Material(
            color: ServiceTokens.card,
            borderRadius: BorderRadius.circular(13.getSize),
            child: InkWell(
              onTap: Get.back,
              borderRadius: BorderRadius.circular(13.getSize),
              child: SizedBox(
                height: 42.getSize,
                width: 42.getSize,
                child: Icon(
                  Icons.chevron_left,
                  color: ServiceTokens.text,
                  size: 21.getSize,
                ),
              ),
            ),
          ),
          Expanded(
            child: BaseTextDMSans(
              text: tr(StringRes.currentLocationTitle),
              fontSize: 16,
              fontWeight: FontWeight.w800,
              color: ServiceTokens.text,
              textAlign: TextAlign.center,
            ),
          ),
          // Balances the back chip so the title sits truly centred.
          SizedBox(width: 42.getSize),
        ],
      ),
    );
  }

  Widget content(CustomerCurrentLocationController controller) {
    return SingleChildScrollView(
      padding: EdgeInsets.symmetric(horizontal: 16.getSize),
      child: Column(
        children: [
          34.heightSpacer,
          const _GpsPulse(),
          36.heightSpacer,
          if (controller.failed)
            failureCard(controller)
          else if (controller.isDetecting)
            detectingCard()
          else
            resultCard(controller),
        ],
      ),
    );
  }

  Widget detectingCard() {
    return panel(
      child: Row(
        children: [
          const CustomerIconTile(icon: Icons.gps_fixed, size: 44),
          14.widthSpacer,
          Expanded(
            child: BaseTextDMSans(
              text: tr(StringRes.detectingLocation),
              fontSize: 14,
              fontWeight: FontWeight.w600,
              color: ServiceTokens.text,
              textAlign: TextAlign.start,
            ),
          ),
        ],
      ),
    );
  }

  Widget failureCard(CustomerCurrentLocationController controller) {
    return panel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              CustomerIconTile(
                icon: Icons.location_disabled,
                size: 44,
                foreground: ColorRes.errorColor,
                background: ColorRes.errorColor.withValues(alpha: 0.14),
              ),
              14.widthSpacer,
              Expanded(
                child: BaseTextDMSans(
                  text: tr(StringRes.locationUnavailable),
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                  color: ServiceTokens.text,
                  textAlign: TextAlign.start,
                ),
              ),
            ],
          ),
          14.heightSpacer,
          BaseTextDMSans(
            text: tr(StringRes.locationUnavailableDesc),
            fontSize: 14,
            color: ServiceTokens.muted,
            textAlign: TextAlign.start,
            maxLines: 3,
          ),
          16.heightSpacer,
          CustomerSecondaryButton(
            label: tr(StringRes.retryDetect),
            icon: Icons.refresh,
            onTap: controller.detect,
          ),
        ],
      ),
    );
  }

  Widget resultCard(CustomerCurrentLocationController controller) {
    final place = controller.place;

    return panel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                height: 8.getSize,
                width: 8.getSize,
                decoration: const BoxDecoration(
                  shape: BoxShape.circle,
                  color: ServiceTokens.green,
                ),
              ),
              9.widthSpacer,
              BaseTextDMSans(
                text: tr(StringRes.detectedViaGps).toUpperCase(),
                fontSize: 10,
                fontWeight: FontWeight.w600,
                letterSpacing: 0.9,
                color: ServiceTokens.green,
              ),
            ],
          ),
          18.heightSpacer,
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const CustomerIconTile(icon: Icons.location_on_outlined, size: 44),
              14.widthSpacer,
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    BaseTextDMSans(
                      text: place?.title ?? tr(StringRes.currentLocationTitle),
                      fontSize: 18,
                      fontWeight: FontWeight.w800,
                      color: ServiceTokens.text,
                      textAlign: TextAlign.start,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    if ((place?.subtitle ?? '').isNotEmpty) ...[
                      6.heightSpacer,
                      BaseTextDMSans(
                        text: place!.subtitle,
                        fontSize: 14,
                        color: ServiceTokens.muted,
                        textAlign: TextAlign.start,
                        maxLines: 3,
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
          20.heightSpacer,
          coordinateRow(
            tr(StringRes.latitudeLabel),
            formatLatitude(controller.latitude),
          ),
          10.heightSpacer,
          coordinateRow(
            tr(StringRes.longitudeLabel),
            formatLongitude(controller.longitude),
          ),
        ],
      ),
    );
  }

  Widget coordinateRow(String label, String value) {
    return Row(
      children: [
        Expanded(
          child: BaseTextDMSans(
            text: label,
            fontSize: 14,
            color: ServiceTokens.muted,
            textAlign: TextAlign.start,
          ),
        ),
        BaseTextDMSans(
          text: value,
          fontSize: 14,
          fontWeight: FontWeight.w600,
          color: ServiceTokens.text,
        ),
      ],
    );
  }

  /// Hemisphere letters rather than a signed number: "22.9965° N" is
  /// what the designs show, and it is what a person can sanity-check.
  String formatLatitude(double? value) {
    if (value == null) {
      return '—';
    }

    return '${value.abs().toStringAsFixed(4)}° ${value >= 0 ? 'N' : 'S'}';
  }

  String formatLongitude(double? value) {
    if (value == null) {
      return '—';
    }

    return '${value.abs().toStringAsFixed(4)}° ${value >= 0 ? 'E' : 'W'}';
  }

  Widget panel({required Widget child}) {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.all(18.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.circular(18.getSize),
        border: Border.all(color: ServiceTokens.stroke),
      ),
      child: child,
    );
  }

  Widget footer(CustomerCurrentLocationController controller) {
    return Padding(
      padding: EdgeInsets.fromLTRB(16.getSize, 10.getSize, 16.getSize, 18.getSize),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          CustomerPrimaryButton(
            label: tr(StringRes.confirmLocation),
            enabled: controller.hasFix && !controller.isDetecting,
            onTap: controller.confirm,
          ),
          14.heightSpacer,
          GestureDetector(
            // Back, not a new route: the select-location screen that
            // opened this one is still underneath, and pushing another
            // copy of it would stack two.
            onTap: Get.back,
            behavior: HitTestBehavior.opaque,
            child: Padding(
              padding: EdgeInsets.symmetric(vertical: 4.getSize),
              child: BaseTextDMSans(
                text: tr(StringRes.chooseDifferentLocation),
                fontSize: 14,
                fontWeight: FontWeight.w600,
                color: ServiceTokens.accentBright,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// The target icon inside three slowly breathing rings.
///
/// Stateful for the ticker, which is the one thing a StatelessWidget
/// cannot own. The motion is what distinguishes "looking for you" from
/// a frozen screen while a GPS fix takes its time.
class _GpsPulse extends StatefulWidget {
  const _GpsPulse();

  @override
  State<_GpsPulse> createState() => _GpsPulseState();
}

class _GpsPulseState extends State<_GpsPulse>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(seconds: 3),
  )..repeat();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 240.getSize,
      width: 240.getSize,
      child: AnimatedBuilder(
        animation: _controller,
        builder: (_, _) {
          return Stack(
            alignment: Alignment.center,
            children: [
              ring(240, 0.0),
              ring(180, 0.33),
              ring(124, 0.66),
              target(),
            ],
          );
        },
      ),
    );
  }

  /// One ring, breathing on its own offset phase so the three do not
  /// pulse in lockstep.
  Widget ring(double size, double phase) {
    final t = (_controller.value + phase) % 1.0;
    // A gentle in-out rather than a linear sweep: linear reads as a
    // mechanical scan, this reads as breathing.
    final eased = Curves.easeInOut.transform(t < 0.5 ? t * 2 : (1 - t) * 2);

    return Container(
      height: size.getSize * (0.96 + eased * 0.06),
      width: size.getSize * (0.96 + eased * 0.06),
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: Border.all(
          color: ServiceTokens.accentBright.withValues(
            alpha: 0.10 + eased * 0.14,
          ),
        ),
      ),
    );
  }

  Widget target() {
    return Container(
      height: 74.getSize,
      width: 74.getSize,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(24.getSize),
        gradient: const LinearGradient(
          colors: [ServiceTokens.accentBright, ServiceTokens.accent],
        ),
        boxShadow: [
          BoxShadow(
            color: ServiceTokens.accent.withValues(alpha: 0.45),
            blurRadius: 26,
            spreadRadius: 2,
          ),
        ],
      ),
      child: Icon(Icons.my_location, color: Colors.white, size: 32.getSize),
    );
  }
}
