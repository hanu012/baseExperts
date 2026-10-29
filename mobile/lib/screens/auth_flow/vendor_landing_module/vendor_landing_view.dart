import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'vendor_landing_controller.dart';

/// Transient routing screen — see VendorLandingController's docblock.
class VendorLandingView extends StatelessWidget {
  const VendorLandingView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorLandingController>(
      init: VendorLandingController(),
      dispose: (_) => Get.delete<VendorLandingController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ColorRes.backgroundColor,
          body: Center(
            child: controller.hasError
                ? errorState(controller)
                : CupertinoActivityIndicator(color: ColorRes.primaryColor),
          ),
        );
      },
    );
  }

  Widget errorState(VendorLandingController controller) {
    return Padding(
      padding: EdgeInsets.all(24.getSize),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          BaseTextDMSans(
            text: StringRes.somethingWentWrong,
            fontSize: 14,
            color: ColorRes.grayColor,
            textAlign: TextAlign.center,
          ).tr(),
          16.heightSpacer,
          BaseRaisedButton(
            onPressed: controller.checkStatusAPI,
            buttonText: StringRes.retry,
            buttonColor: ColorRes.primaryColor,
          ),
        ],
      ),
    );
  }
}
