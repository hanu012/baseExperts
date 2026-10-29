/// GET /api/salesmen/me — the salesman's own record plus their headline
/// numbers (SPEC section 2.5).
///
/// Identity and stats arrive together because the profile screen renders
/// both at once; splitting them would mean two round trips to draw one
/// screen.
class SalesmanProfileModel {
  int? id;
  String? name;
  String? email;
  String? avatarUrl;
  String? employeeCode;
  String? phone;
  String? region;
  bool isActive;

  /// Pre-formatted by the server ("12.00"). Never derive this from
  /// [commissionRateBps] in the app — rounding it differently is how a
  /// salesman ends up seeing a rate that disagrees with their payout.
  String? commissionRatePercent;
  int? commissionRateBps;

  int? monthlyTargetPaise;
  String? language;
  bool enableNotification;

  SalesmanStatsModel? stats;

  SalesmanProfileModel({
    this.id,
    this.name,
    this.email,
    this.employeeCode,
    this.phone,
    this.region,
    this.isActive = true,
    this.commissionRatePercent,
    this.commissionRateBps,
    this.monthlyTargetPaise,
    this.language,
    this.enableNotification = true,
    this.stats,
  });

  /// Takes the whole `{salesman: {...}, stats: {...}}` envelope.
  SalesmanProfileModel.fromJson(Map<String, dynamic> json)
      : id = _salesman(json)?['id'] as int?,
        name = _salesman(json)?['name'] as String?,
        email = _salesman(json)?['email'] as String?,
        avatarUrl = _salesman(json)?['avatar_url'] as String?,
        employeeCode = _salesman(json)?['employee_code'] as String?,
        phone = _salesman(json)?['phone'] as String?,
        region = _salesman(json)?['region'] as String?,
        isActive = _salesman(json)?['is_active'] as bool? ?? true,
        commissionRatePercent = _salesman(json)?['commission_rate_percent'] as String?,
        commissionRateBps = _salesman(json)?['commission_rate_bps'] as int?,
        monthlyTargetPaise = _salesman(json)?['monthly_target_paise'] as int?,
        language = _salesman(json)?['language'] as String?,
        enableNotification = _salesman(json)?['enable_notification'] as bool? ?? true,
        stats = json['stats'] is Map<String, dynamic>
            ? SalesmanStatsModel.fromJson(json['stats'] as Map<String, dynamic>)
            : null;

  static Map<String, dynamic>? _salesman(Map<String, dynamic> json) =>
      json['salesman'] as Map<String, dynamic>?;

  /// Two letters for the avatar tile. Falls back to a single glyph rather
  /// than rendering an empty square when the name is one word or missing.
  String get initials {
    final parts = (name ?? '').trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();

    if (parts.isEmpty) {
      return '?';
    }
    if (parts.length == 1) {
      return parts.first.characters2;
    }

    return '${parts.first[0]}${parts[1][0]}'.toUpperCase();
  }
}

extension on String {
  /// First two characters, uppercased — for a single-word name.
  String get characters2 =>
      (length >= 2 ? substring(0, 2) : substring(0, 1)).toUpperCase();
}

class SalesmanStatsModel {
  int totalVendors;
  int subscribedVendors;
  int earningsPaise;
  int pendingEarningsPaise;
  SalesmanTargetModel? target;

  SalesmanStatsModel({
    this.totalVendors = 0,
    this.subscribedVendors = 0,
    this.earningsPaise = 0,
    this.pendingEarningsPaise = 0,
    this.target,
  });

  SalesmanStatsModel.fromJson(Map<String, dynamic> json)
      : totalVendors = json['total_vendors'] as int? ?? 0,
        subscribedVendors = json['subscribed_vendors'] as int? ?? 0,
        earningsPaise = json['earnings_paise'] as int? ?? 0,
        pendingEarningsPaise = json['pending_earnings_paise'] as int? ?? 0,
        target = json['target'] is Map<String, dynamic>
            ? SalesmanTargetModel.fromJson(json['target'] as Map<String, dynamic>)
            : null;
}

/// Monthly target progress, measured in revenue SOLD rather than commission
/// earned — see SalesmanController::stats() for why.
class SalesmanTargetModel {
  int monthlyTargetPaise;
  int achievedPaise;

  /// Null when no target is set. "No target" and "0% of a target" are
  /// different states: the screen hides the bar for the former rather than
  /// drawing an empty one that reads as failure.
  int? percent;

  SalesmanTargetModel({
    this.monthlyTargetPaise = 0,
    this.achievedPaise = 0,
    this.percent,
  });

  SalesmanTargetModel.fromJson(Map<String, dynamic> json)
      : monthlyTargetPaise = json['monthly_target_paise'] as int? ?? 0,
        achievedPaise = json['achieved_paise'] as int? ?? 0,
        percent = json['percent'] as int?;

  bool get hasTarget => percent != null;
}
