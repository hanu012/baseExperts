import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../vendor_detail_module/vendor_detail_view.dart';
import 'customer_calls_controller.dart';

/// Profile → My calls. Shown only while the admin switch "Call tracking
/// in apps" is on.
class CustomerCallsView extends StatelessWidget {
  const CustomerCallsView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<CustomerCallsController>(
      init: CustomerCallsController(),
      dispose: (_) => Get.delete<CustomerCallsController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          appBar: AppBar(
            backgroundColor: ServiceTokens.bg,
            surfaceTintColor: Colors.transparent,
            elevation: 0,
            title: BaseTextDMSans(
              text: StringRes.myCalls,
              fontSize: 18,
              fontWeight: FontWeight.w800,
              color: ServiceTokens.text,
            ).tr(),
          ),
          body: body(controller),
        );
      },
    );
  }

  Widget body(CustomerCallsController controller) {
    if (controller.isLoading && controller.calls.isEmpty) {
      return Center(child: CupertinoActivityIndicator(color: ColorRes.primaryColor));
    }

    if (controller.calls.isEmpty) {
      return Center(
        child: Padding(
          padding: EdgeInsets.all(24.getSize),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.phone_outlined, size: 40.getSize, color: ServiceTokens.muted),
              12.heightSpacer,
              BaseTextDMSans(
                text: StringRes.noCallsYet,
                fontSize: 16,
                fontWeight: FontWeight.w600,
                color: ServiceTokens.text,
              ).tr(),
              4.heightSpacer,
              BaseTextDMSans(
                text: StringRes.noCallsYetDesc,
                fontSize: 12,
                color: ServiceTokens.muted,
              ).tr(),
            ],
          ),
        ),
      );
    }

    return RefreshIndicator(
      color: ColorRes.primaryColor,
      onRefresh: controller.fetchAPI,
      child: ListView.separated(
        padding: EdgeInsets.all(16.getSize),
        itemCount: controller.calls.length + 1 + (controller.hasMore ? 1 : 0),
        separatorBuilder: (_, _) => 12.heightSpacer,
        itemBuilder: (_, index) {
          if (index == 0) {
            return BaseTextDMSans(
              text: StringRes.callTimesApprox,
              fontSize: 12,
              color: ServiceTokens.muted,
              textAlign: TextAlign.start,
            ).tr();
          }

          if (index == controller.calls.length + 1) {
            return loadMoreButton(controller);
          }

          return callCard(controller.calls[index - 1]);
        },
      ),
    );
  }

  Widget loadMoreButton(CustomerCallsController controller) {
    return Center(
      child: controller.isLoadingMore
          ? CupertinoActivityIndicator(color: ColorRes.primaryColor)
          : TextButton(
              onPressed: () => controller.fetchAPI(loadMore: true),
              child: BaseTextDMSans(
                text: StringRes.loadMore,
                fontSize: 12,
                color: ColorRes.primaryColor,
              ).tr(),
            ),
    );
  }

  Widget callCard(CustomerCallModel call) {
    final isWhatsapp = call.channel == 'whatsapp';
    final when = DateTime.tryParse(call.createdAt ?? '')?.toLocal();
    final length = Utils.approxCallLength(call.callDurationSeconds);

    return InkWell(
      borderRadius: BorderRadius.circular(14.getSize),
      onTap: call.vendorId == null
          ? null
          : () => Get.to(
                () => VendorDetailView(
                  vendorId: call.vendorId!,
                  subcategoryId: call.subcategoryId,
                  zoneId: null,
                  latitude: Injector.customerLatitude,
                  longitude: Injector.customerLongitude,
                ),
              ),
      child: Container(
        padding: EdgeInsets.all(12.getSize),
        decoration: BoxDecoration(
          color: ServiceTokens.card,
          borderRadius: BorderRadius.circular(14.getSize),
          border: Border.all(color: ServiceTokens.stroke),
        ),
        child: Row(
          children: [
            VendorIconTile(icon: isWhatsapp ? Icons.chat_outlined : Icons.call_outlined),
            12.widthSpacer,
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  BaseTextDMSans(
                    text: call.vendorName ?? '-',
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                    color: ServiceTokens.text,
                    textAlign: TextAlign.start,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  2.heightSpacer,
                  BaseTextDMSans(
                    text: [
                      call.subcategoryName,
                      if (when != null) DateFormat('dd MMM, hh:mm a').format(when),
                    ].whereType<String>().join(' · '),
                    fontSize: 12,
                    color: ServiceTokens.muted,
                    textAlign: TextAlign.start,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
            8.widthSpacer,
            BaseTextDMSans(
              text: isWhatsapp ? tr(StringRes.whatsappButton) : length,
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: ServiceTokens.text,
            ),
          ],
        ),
      ),
    );
  }
}
