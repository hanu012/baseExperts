<?php

namespace App\Filament\Widgets;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Salesman;
use App\Models\Subcategory;
use App\Models\Subscription;
use App\Models\Vendor;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * The admin Dashboard (SPEC section 5 item 1) — eight real counts/sums
 * against real tables, no permission gate (visible to every admin-role
 * user; nothing in this app currently gates a Filament Page, only
 * Resources via their model policies, and SPEC doesn't ask for one
 * here).
 *
 * Revenue is a plain SUM(payments.amount_paise) for the calendar
 * month — deliberately not adjusted for anything. There is no refund/
 * void mechanism anywhere in this codebase, and a change-plan's
 * proration (task 4.7) is already baked into the amount charged at
 * write time (including the legitimate amount_paise = 0 row when a
 * downgrade credit fully covers the new plan's price), so a plain sum
 * is already the complete, correct figure.
 */
class DashboardStatsOverview extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();

        $revenuePaise = (int) Payment::whereBetween('created_at', [$monthStart, $monthEnd])
            ->sum('amount_paise');

        return [
            Stat::make('Vendors', Vendor::count()),
            Stat::make('Salesmen', Salesman::count()),
            Stat::make('Customers', Customer::count()),
            Stat::make('Services', Category::count()),
            Stat::make('Subservices', Subcategory::count()),

            // The [status, end_date] index was built anticipating this
            // exact query (see the subscriptions migration's own
            // comment). A `superseded` row can never appear here: it's
            // never `status = 'active'` and its `end_date` was rewound
            // to the day it was replaced, in the same atomic update.
            Stat::make(
                'Expiring in 30 days',
                Subscription::where('status', 'active')
                    ->whereBetween('end_date', [now(), now()->addDays(30)])
                    ->count()
            ),

            Stat::make('Revenue this month', Number::currency($revenuePaise / 100, 'INR')),

            // Reuses the exact scope VendorVerificationResource's own
            // queue is built on, so "pending verification" can never
            // drift between the two.
            Stat::make('Pending verification', Vendor::pendingVerification()->count()),

            Stat::make(
                'Leads this week',
                Lead::whereBetween('created_at', [$weekStart, $weekEnd])->count()
            ),
        ];
    }
}
