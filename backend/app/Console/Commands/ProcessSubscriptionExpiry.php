<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily lifecycle sweep (BUILD_PLAN 7.1, SPEC section 7):
 * Active -> Grace at end_date, Grace -> Expired once grace_period_days
 * (Setting, default 7) has elapsed since end_date.
 *
 * Two passes, each scoped with whereHas('vendor', ...) rather than
 * cascading the vendor-status flip unconditionally: a self-registered
 * vendor's subscription is created `active` by SubscriptionService
 * BEFORE admin approval, and VendorVerificationService::reject() only
 * ever touches vendor.status, never the subscription. Without the
 * vendor-status guard, a rejected vendor's dangling active
 * subscription would silently resurrect them from `rejected` to
 * `grace` once its (irrelevant) end_date lapsed. is_suspended is a
 * separate, orthogonal flag (SPEC section 7) and is never touched
 * here either way.
 *
 * Each row is updated via a real Eloquent update() per model, never a
 * mass query-builder update — RecordsAuditLog (already on both
 * Subscription and Vendor) only fires on the former.
 */
class ProcessSubscriptionExpiry extends Command
{
    protected $signature = 'subscriptions:process-expiry';

    protected $description = 'Advance subscriptions/vendors through Active -> Grace -> Expired based on end_date and the grace period.';

    public function handle(): int
    {
        $today = Carbon::today();
        $gracePeriodDays = (int) Setting::get('grace_period_days', 7);

        $activeToGrace = $this->advance(
            fromStatus: 'active',
            toStatus: 'grace',
            cutoff: $today,
        );

        $graceToExpired = $this->advance(
            fromStatus: 'grace',
            toStatus: 'expired',
            cutoff: $today->copy()->subDays($gracePeriodDays),
        );

        $this->info("Active -> Grace: {$activeToGrace}. Grace -> Expired: {$graceToExpired}.");

        return self::SUCCESS;
    }

    private function advance(string $fromStatus, string $toStatus, Carbon $cutoff): int
    {
        $count = 0;

        Subscription::query()
            ->where('status', $fromStatus)
            ->where('end_date', '<', $cutoff)
            ->whereHas('vendor', fn ($query) => $query->where('status', $fromStatus))
            ->chunkById(100, function ($subscriptions) use (&$count, $toStatus) {
                foreach ($subscriptions as $subscription) {
                    $subscription->update(['status' => $toStatus]);
                    $subscription->vendor->update(['status' => $toStatus]);
                    $count++;
                }
            });

        return $count;
    }
}
