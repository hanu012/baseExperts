/// Barrel export. Every screen file starts with a single import of this,
/// rather than importing constants and utils piecemeal — see the Flutter
/// conventions in CLAUDE.md.
library;

export 'dart:convert';

export 'package:flutter/foundation.dart';
export 'package:flutter/material.dart';
export 'package:flutter/services.dart';
export 'package:fluttertoast/fluttertoast.dart';
export 'package:get/get.dart';
export 'package:shared_preferences/shared_preferences.dart';

export '../common_model/category_model.dart';
export '../common_model/commission_summary_model.dart';
export '../common_model/common_response.dart';
export '../common_model/customer_address_model.dart';
export '../common_model/customer_location_model.dart';
export '../common_model/lead_model.dart';
export '../common_model/plan_model.dart';
export '../common_model/portfolio_media_model.dart';
export '../common_model/review_model.dart';
export '../common_model/salesman_profile_model.dart';
export '../common_model/salesman_vendor_model.dart';
export '../common_model/subscription_model.dart';
export '../common_model/user_model.dart';
export '../common_model/vendor_document_model.dart';
export '../common_model/vendor_detail_model.dart';
export '../common_model/vendor_me_model.dart';
export '../common_model/vendor_review_model.dart';
export '../common_model/vendor_search_model.dart';
export '../common_model/zone_model.dart';
export '../network/data_source.dart';
export '../utils/injector.dart';
export '../utils/utils.dart';
export '../widgets/base_auth.dart';
export '../widgets/base_button.dart';
export '../widgets/base_customer.dart';
export '../widgets/base_language.dart';
export '../widgets/base_portfolio.dart';
export '../widgets/base_profile.dart';
export '../widgets/base_salesman.dart';
export '../widgets/base_services.dart';
export '../widgets/base_text.dart';
export '../widgets/base_textfield.dart';
export '../widgets/base_vendor.dart';
export 'color_res.dart';
export 'pref_keys.dart';
export 'string_res.dart';

// constant.dart is deliberately NOT exported here. Its IntExtension collides
// with GetX's, so screens import it directly — which is exactly what the
// demo-app screens do:
//
//   import '../../../constants/app.export.dart';
//   import '../../../constants/constant.dart';
