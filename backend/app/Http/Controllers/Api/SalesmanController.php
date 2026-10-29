<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Salesman\UpdateSalesmanProfileRequest;
use App\Http\Resources\SalesmanProfileResource;
use App\Http\Resources\SalesmanVendorResource;
use App\Http\Responses\ApiResponse;
use App\Models\Salesman;
use App\Models\Subscription;
use App\Models\Vendor;
use App\Support\VendorQuotaUsageBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A salesman's own records — My Vendors and Earnings (SPEC sections 2.3,
 * 2.4). The first `/me/...` routes in this API: everything here is scoped
 * to $request->user()->salesman, never to a route-bound id, so there is no
 * ownership check to get wrong.
 */
class SalesmanController extends Controller
{
    /**
     * My Vendors: name, plan, days to expiry. See SalesmanVendorResource
     * for why leads-this-month is absent and why this isn't filtered to
     * active-only.
     */
    public function vendors(Request $request): JsonResponse
    {
        $vendors = $request->user()->salesman->vendors()
            ->with(['subscriptions' => fn ($query) => $query->with('plan.quota', 'addons')->latest('end_date')])
            ->orderBy('business_name')
            ->get();

        // One batched pass for the whole list rather than a per-row resolve,
        // which would be a handful of queries per vendor.
        $usage = new VendorQuotaUsageBatch($vendors);

        return ApiResponse::success(
            $vendors
                ->map(fn (Vendor $vendor) => (new SalesmanVendorResource($vendor))->withQuota($usage->for($vendor)))
                ->all()
        );
    }

    /**
     * The salesman's own profile and headline numbers (SPEC section 2.5).
     *
     * Bundles identity, preferences and stats in one call because the
     * profile screen renders all three at once — splitting them would mean
     * three round trips to draw one screen.
     */
    public function me(Request $request): JsonResponse
    {
        $salesman = $request->user()->salesman->load('user');

        return ApiResponse::success([
            'salesman' => new SalesmanProfileResource($salesman),
            'stats' => $this->stats($salesman),
        ]);
    }

    /**
     * Updates what a salesman may change about themselves.
     *
     * Name and phone only. Email is excluded on purpose: changing it has to
     * re-run verification (SPEC section 7) and would strand the account if
     * the new address were wrong, so it stays an admin action.
     * `employee_code`, `region`, the target and the commission rate are all
     * organisational facts an admin owns — a salesman editing their own
     * commission rate is the obvious reason none of them are here.
     */
    public function updateMe(UpdateSalesmanProfileRequest $request): JsonResponse
    {
        $salesman = $request->user()->salesman;

        DB::transaction(function () use ($request, $salesman) {
            if ($request->has('name')) {
                $salesman->user->update(['name' => $request->string('name')->toString()]);
            }

            if ($request->has('phone')) {
                $salesman->update(['phone' => $request->string('phone')->toString()]);
            }
        });

        return ApiResponse::success([
            'salesman' => new SalesmanProfileResource($salesman->fresh()->load('user')),
        ]);
    }

    /**
     * Headline numbers for the profile and home screens.
     *
     * TARGET PROGRESS IS MEASURED IN REVENUE SOLD, not commission earned:
     * `monthly_target_paise` is a sales target, and a salesman on a 12%
     * rate would otherwise appear to hit 12% of their target after selling
     * exactly all of it. Commissions remain reported separately as
     * earnings. This resolves the policy question commissions() flags as
     * undecided — narrowly, for this one figure.
     *
     * @return array<string, mixed>
     */
    private function stats(Salesman $salesman): array
    {
        $vendors = $salesman->vendors()->get(['id']);

        $subscribedCount = Subscription::query()
            ->whereIn('vendor_id', $vendors->pluck('id'))
            ->where('end_date', '>=', now())
            ->distinct('vendor_id')
            ->count('vendor_id');

        $soldThisMonthPaise = (int) Subscription::query()
            ->where('salesman_id', $salesman->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('price_paise');

        $target = $salesman->monthly_target_paise;

        return [
            'total_vendors' => $vendors->count(),
            'subscribed_vendors' => $subscribedCount,
            'earnings_paise' => (int) $salesman->commissions()->where('status', 'paid')->sum('amount_paise'),
            'pending_earnings_paise' => (int) $salesman->commissions()->where('status', 'pending')->sum('amount_paise'),
            'target' => [
                'monthly_target_paise' => $target,
                'achieved_paise' => $soldThisMonthPaise,
                // Null rather than 0 when no target is set: "no target" and
                // "0% of a target" are different states, and the app should
                // hide the progress bar for the former rather than draw an
                // empty one that looks like failure.
                'percent' => $target > 0
                    ? (int) min(100, round($soldThisMonthPaise / $target * 100))
                    : null,
            ],
        ];
    }

    /**
     * Earnings: pending/paid commission totals only. SPEC section 2.4 also
     * lists "monthly target vs achieved" and "cash collected but not yet
     * reconciled" — both deliberately out of this endpoint. The target
     * comparison needs a policy decision (commission earned vs. revenue
     * sold this month aren't the same number) that hasn't been made yet;
     * the cash-reconciliation view is a separate, unrelated feature.
     */
    public function commissions(Request $request): JsonResponse
    {
        $commissions = $request->user()->salesman->commissions();

        return ApiResponse::success([
            'pending_amount_paise' => (int) (clone $commissions)->where('status', 'pending')->sum('amount_paise'),
            'paid_amount_paise' => (int) (clone $commissions)->where('status', 'paid')->sum('amount_paise'),
            'pending_count' => (clone $commissions)->where('status', 'pending')->count(),
            'paid_count' => (clone $commissions)->where('status', 'paid')->count(),
        ]);
    }
}
