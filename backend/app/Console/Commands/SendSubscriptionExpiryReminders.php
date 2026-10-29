<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\PushNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * SPEC section 5.12's expiry reminders (T-15/T-7/T-1, BUILD_PLAN 7.2)
 * — distinct from subscriptions:process-expiry (task 7.1), which
 * moves lifecycle status. This command only sends a heads-up while a
 * subscription is still `active`; Grace has its own, different
 * messaging and isn't touched here.
 *
 * Idempotency: each threshold gets its own nullable
 * reminder_sent_tN_at column on `subscriptions` (same "timestamp on
 * the owning row, set once" idiom as leads.review_requested_at,
 * task 4.8) — NOT tracked via the `notifications` dispatch log, which
 * still gets a row per send regardless, for a different purpose
 * (audit/visibility, not "have we already done this").
 */
class SendSubscriptionExpiryReminders extends Command
{
    protected $signature = 'subscriptions:send-expiry-reminders';

    protected $description = 'Send a one-time push reminder at T-15/T-7/T-1 days before an active subscription expires.';

    /**
     * @var array<int, string>
     */
    private const THRESHOLDS = [
        15 => 'reminder_sent_t15_at',
        7 => 'reminder_sent_t7_at',
        1 => 'reminder_sent_t1_at',
    ];

    /**
     * One reminder per subscription per run, stating the real days left.
     *
     * Every threshold the subscription has already reached is stamped at
     * once, but only one notification goes out. That is what short terms
     * need: a 7-day free trial is inside both the 15- and 7-day windows on
     * day one, and used to get two pushes in the same minute, one of them
     * claiming "15 days" for a plan that only had 7.
     */
    public function handle(PushNotificationService $notifications): int
    {
        $today = Carbon::today();
        $widest = max(array_keys(self::THRESHOLDS));
        $sent = 0;

        Subscription::query()
            ->where('status', 'active')
            ->where('end_date', '>=', $today)
            ->where('end_date', '<=', $today->copy()->addDays($widest))
            ->where(function ($query) {
                foreach (self::THRESHOLDS as $column) {
                    $query->orWhereNull($column);
                }
            })
            ->chunkById(100, function ($subscriptions) use (&$sent, $notifications, $today) {
                foreach ($subscriptions as $subscription) {
                    $daysLeft = (int) round(abs($today->diffInDays($subscription->end_date->copy()->startOfDay())));

                    // Thresholds this subscription has reached and not yet
                    // been reminded about.
                    $due = [];

                    foreach (self::THRESHOLDS as $days => $column) {
                        if ($daysLeft <= $days && $subscription->{$column} === null) {
                            $due[] = $column;
                        }
                    }

                    if ($due === []) {
                        continue;
                    }

                    $notifications->notifySubscriptionExpiring($subscription, $daysLeft);
                    $subscription->update(array_fill_keys($due, now()));
                    $sent++;
                }
            });

        $this->info("Reminders sent: {$sent}.");

        return self::SUCCESS;
    }
}
