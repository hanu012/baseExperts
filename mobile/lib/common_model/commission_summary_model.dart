/// Pending/paid commission totals (`GET /api/salesmen/me/commissions`,
/// SPEC section 2.4).
///
/// Deliberately just the totals — no monthly-target comparison. The
/// `monthly_target_paise` column exists on `salesmen` but what "achieved"
/// should mean (commission earned this month vs. total sale value sold
/// this month) is an undecided policy question, left flagged rather than
/// guessed at.
class CommissionSummaryModel {
  int? pendingAmountPaise;
  int? paidAmountPaise;
  int? pendingCount;
  int? paidCount;

  CommissionSummaryModel({
    this.pendingAmountPaise,
    this.paidAmountPaise,
    this.pendingCount,
    this.paidCount,
  });

  CommissionSummaryModel.fromJson(Map<String, dynamic> json)
      : pendingAmountPaise = json['pending_amount_paise'] as int?,
        paidAmountPaise = json['paid_amount_paise'] as int?,
        pendingCount = json['pending_count'] as int?,
        paidCount = json['paid_count'] as int?;
}
