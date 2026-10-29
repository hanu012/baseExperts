import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'splash_controller.dart';

/// Brand splash shared by all three flavours: the app's icon tile, its name
/// and strapline on the header gradient.
///
/// White text is correct here — the header gradient is the one dark
/// surface left in the light theme (CLAUDE.md, Theme).
class SplashView extends StatelessWidget {
  const SplashView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<SplashController>(
      init: SplashController(),
      dispose: (_) => Get.delete<SplashController>(),
      builder: (controller) => Scaffold(
        body: Container(
          width: double.infinity,
          height: double.infinity,
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [ColorRes.primaryColor, ColorRes.primaryColorDark],
            ),
          ),
          child: SafeArea(
            child: Padding(
              padding: EdgeInsets.symmetric(horizontal: 32.getSize),
              child: Column(
                children: [
                  const Spacer(),
                  brandTile(controller),
                  24.heightSpacer,
                  BaseTextDMSans(
                    text: controller.brandTitle,
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                    color: ColorRes.whiteColor,
                    textAlign: TextAlign.center,
                    maxLines: 2,
                  ).tr(),
                  8.heightSpacer,
                  BaseTextDMSans(
                    text: controller.brandSubtitle,
                    fontSize: 12,
                    fontWeight: FontWeight.w400,
                    color: ColorRes.whiteColor.withValues(alpha: 0.82),
                    textAlign: TextAlign.center,
                    maxLines: 3,
                  ).tr(),
                  const Spacer(),
                  SizedBox(
                    height: 22.getSize,
                    width: 22.getSize,
                    child: CircularProgressIndicator(
                      strokeWidth: 2.4,
                      color: ColorRes.whiteColor,
                    ),
                  ),
                  32.heightSpacer,
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget brandTile(SplashController controller) {
    return Container(
      height: 96.getSize,
      width: 96.getSize,
      decoration: BoxDecoration(
        color: ColorRes.whiteColor,
        borderRadius: BorderRadius.circular(28.getSize),
        boxShadow: [
          BoxShadow(
            color: ColorRes.blackColor.withValues(alpha: 0.18),
            blurRadius: 28.getSize,
            offset: Offset(0, 12.getSize),
          ),
        ],
      ),
      child: Icon(
        controller.brandIcon,
        size: 46.getSize,
        color: ColorRes.primaryColor,
      ),
    );
  }
}
