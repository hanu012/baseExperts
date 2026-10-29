import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'customer_map_location_controller.dart';

/// "Choose on map" (SPEC section 4.2) — the map, a fixed centre pin, and
/// a sheet showing whatever is under it.
class CustomerMapLocationView extends StatelessWidget {
  const CustomerMapLocationView({super.key, this.initialPoint});

  /// Where to open. Null opens on the customer's GPS position, falling
  /// back to the seeded service area.
  final LatLng? initialPoint;

  @override
  Widget build(BuildContext context) {
    return GetBuilder<CustomerMapLocationController>(
      init: CustomerMapLocationController(initialPoint: initialPoint),
      dispose: (_) => Get.delete<CustomerMapLocationController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          body: Stack(
            children: [
              Positioned.fill(child: map(controller)),
              Positioned.fill(child: IgnorePointer(child: centrePin())),
              Positioned(top: 0, left: 0, right: 0, child: topBar(controller)),
              Positioned(
                right: 16.getSize,
                bottom: 250.getSize,
                child: locateButton(controller),
              ),
              Positioned(left: 0, right: 0, bottom: 0, child: sheet(controller)),
            ],
          ),
        );
      },
    );
  }

  Widget map(CustomerMapLocationController controller) {
    return GoogleMap(
      initialCameraPosition: CameraPosition(target: controller.centre, zoom: 16),
      onMapCreated: controller.onMapCreated,
      onCameraMove: controller.onCameraMove,
      onCameraIdle: controller.onCameraIdle,
      // The pin is drawn by this screen over the map's centre, so the
      // map's own location dot and controls would be duplicate chrome.
      myLocationEnabled: true,
      myLocationButtonEnabled: false,
      zoomControlsEnabled: false,
      mapToolbarEnabled: false,
      style: _mapStyle,
    );
  }

  /// The pin, pinned to the middle of the screen rather than to the map.
  Widget centrePin() {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Stack(
            alignment: Alignment.center,
            children: [
              Container(
                height: 76.getSize,
                width: 76.getSize,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: ServiceTokens.accent.withValues(alpha: 0.18),
                ),
              ),
              Icon(
                Icons.location_on,
                size: 56.getSize,
                color: ServiceTokens.accentBright,
              ),
            ],
          ),
          // Offsets the pin so its tip, not its middle, marks the point.
          SizedBox(height: 44.getSize),
        ],
      ),
    );
  }

  Widget topBar(CustomerMapLocationController controller) {
    return SafeArea(
      bottom: false,
      child: Padding(
        padding: EdgeInsets.fromLTRB(16.getSize, 10.getSize, 16.getSize, 0),
        child: Row(
          children: [
            circleButton(icon: Icons.chevron_left, onTap: Get.back),
            12.widthSpacer,
            Expanded(child: titlePill(controller)),
          ],
        ),
      ),
    );
  }

  /// Shows what is under the pin, in the slot the search field occupies
  /// in the design. Not a field: searching belongs on the previous
  /// screen, and two search entry points would be two sources of truth.
  Widget titlePill(CustomerMapLocationController controller) {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: 14.getSize, vertical: 13.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.circular(14.getSize),
        border: Border.all(color: ServiceTokens.stroke),
      ),
      child: Row(
        children: [
          Icon(Icons.search, color: ServiceTokens.muted2, size: 19.getSize),
          10.widthSpacer,
          Expanded(
            child: BaseTextDMSans(
              text: controller.place?.title ?? tr(StringRes.mapLocationTitle),
              fontSize: 14,
              fontWeight: FontWeight.w600,
              color: ServiceTokens.text,
              textAlign: TextAlign.start,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
      ),
    );
  }

  Widget circleButton({required IconData icon, required VoidCallback onTap}) {
    return Material(
      color: ServiceTokens.card,
      borderRadius: BorderRadius.circular(13.getSize),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(13.getSize),
        child: Container(
          height: 46.getSize,
          width: 46.getSize,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(13.getSize),
            border: Border.all(color: ServiceTokens.stroke),
          ),
          child: Icon(icon, color: ServiceTokens.text, size: 21.getSize),
        ),
      ),
    );
  }

  Widget locateButton(CustomerMapLocationController controller) {
    return Material(
      color: ServiceTokens.card,
      shape: const CircleBorder(),
      child: InkWell(
        onTap: controller.goToMyLocation,
        customBorder: const CircleBorder(),
        child: Container(
          height: 48.getSize,
          width: 48.getSize,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            border: Border.all(color: ServiceTokens.stroke),
          ),
          child: Icon(
            Icons.my_location,
            color: ServiceTokens.accentBright,
            size: 21.getSize,
          ),
        ),
      ),
    );
  }

  Widget sheet(CustomerMapLocationController controller) {
    return Container(
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.vertical(top: Radius.circular(22.getSize)),
        border: Border.all(color: ServiceTokens.stroke),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: EdgeInsets.fromLTRB(16.getSize, 10.getSize, 16.getSize, 16.getSize),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Center(
                child: Container(
                  height: 4.getSize,
                  width: 46.getSize,
                  decoration: BoxDecoration(
                    color: ServiceTokens.stroke2,
                    borderRadius: BorderRadius.circular(3.getSize),
                  ),
                ),
              ),
              16.heightSpacer,
              hintPill(),
              16.heightSpacer,
              CustomerGroupLabel(
                text: tr(StringRes.selectedLocationLabel).toUpperCase(),
              ),
              12.heightSpacer,
              selectedRow(controller),
              14.heightSpacer,
              coordinatePill(controller),
              18.heightSpacer,
              CustomerPrimaryButton(
                label: tr(StringRes.confirmLocation),
                onTap: controller.confirm,
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget hintPill() {
    return Center(
      child: Container(
        padding: EdgeInsets.symmetric(horizontal: 14.getSize, vertical: 8.getSize),
        decoration: BoxDecoration(
          color: ServiceTokens.bg,
          borderRadius: BorderRadius.circular(20.getSize),
          border: Border.all(color: ServiceTokens.stroke),
        ),
        child: BaseTextDMSans(
          text: tr(StringRes.moveMapToAdjustPin),
          fontSize: 12,
          fontWeight: FontWeight.w600,
          color: ServiceTokens.muted,
        ),
      ),
    );
  }

  Widget selectedRow(CustomerMapLocationController controller) {
    final place = controller.place;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const CustomerIconTile(icon: Icons.location_on_outlined, size: 42),
        12.widthSpacer,
        Expanded(
          child: controller.isResolving && place == null
              ? Padding(
                  padding: EdgeInsets.only(top: 8.getSize),
                  child: const Align(
                    alignment: Alignment.centerLeft,
                    child: CupertinoActivityIndicator(
                      color: ServiceTokens.accentBright,
                    ),
                  ),
                )
              : Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    BaseTextDMSans(
                      text: place?.title ?? tr(StringRes.selectedLocationLabel),
                      fontSize: 16,
                      fontWeight: FontWeight.w800,
                      color: ServiceTokens.text,
                      textAlign: TextAlign.start,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    if ((place?.subtitle ?? '').isNotEmpty) ...[
                      4.heightSpacer,
                      BaseTextDMSans(
                        text: place!.subtitle,
                        fontSize: 12,
                        color: ServiceTokens.muted,
                        textAlign: TextAlign.start,
                        maxLines: 2,
                      ),
                    ],
                  ],
                ),
        ),
      ],
    );
  }

  Widget coordinatePill(CustomerMapLocationController controller) {
    final lat = controller.centre.latitude;
    final lng = controller.centre.longitude;

    return Container(
      padding: EdgeInsets.symmetric(horizontal: 12.getSize, vertical: 8.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.bg,
        borderRadius: BorderRadius.circular(10.getSize),
        border: Border.all(color: ServiceTokens.stroke),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.gps_fixed, size: 14.getSize, color: ServiceTokens.muted2),
          8.widthSpacer,
          BaseTextDMSans(
            text: '${lat.abs().toStringAsFixed(4)}° ${lat >= 0 ? 'N' : 'S'}'
                ' · ${lng.abs().toStringAsFixed(4)}° ${lng >= 0 ? 'E' : 'W'}',
            fontSize: 12,
            fontWeight: FontWeight.w600,
            color: ServiceTokens.muted,
          ),
        ],
      ),
    );
  }
}

/// Light map styling, tuned to sit under the app's off-white canvas
/// rather than fight it. Google's own JSON style format.
const String _mapStyle = '''
[
  {"elementType":"geometry","stylers":[{"color":"#f6f7f9"}]},
  {"elementType":"labels.icon","stylers":[{"visibility":"off"}]},
  {"elementType":"labels.text.fill","stylers":[{"color":"#5b6475"}]},
  {"elementType":"labels.text.stroke","stylers":[{"color":"#ffffff"}]},
  {"featureType":"administrative","elementType":"geometry","stylers":[{"color":"#e4e7ec"}]},
  {"featureType":"poi","elementType":"labels.text.fill","stylers":[{"color":"#98a2b3"}]},
  {"featureType":"poi.park","elementType":"geometry","stylers":[{"color":"#d9f2ec"}]},
  {"featureType":"road","elementType":"geometry","stylers":[{"color":"#ffffff"}]},
  {"featureType":"road","elementType":"labels.text.fill","stylers":[{"color":"#667085"}]},
  {"featureType":"road.highway","elementType":"geometry","stylers":[{"color":"#ccfbf1"}]},
  {"featureType":"transit","elementType":"geometry","stylers":[{"color":"#e4e7ec"}]},
  {"featureType":"water","elementType":"geometry","stylers":[{"color":"#cfe8f3"}]},
  {"featureType":"water","elementType":"labels.text.fill","stylers":[{"color":"#5b8aa8"}]}
]
''';
