<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The salesman's own profile (SPEC section 2.5).
 *
 * Until this existed, `employee_code`, `phone`, `region`, the monthly
 * target and the commission rate were all reachable only from the admin
 * panel — the app had no way to show a salesman their own record.
 *
 * `commission_rate_bps` is exposed as a percent string alongside the raw
 * basis points: the app should never do that division itself, because
 * rounding it differently from Salesman::commissionRatePercent() is how a
 * salesman ends up seeing a rate that disagrees with their payout.
 *
 * @mixin \App\Models\Salesman
 */
class SalesmanProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'avatar_url' => $this->user->avatarUrl(),
            'employee_code' => $this->employee_code,
            'phone' => $this->phone,
            'region' => $this->region,
            'is_active' => $this->is_active,
            'commission_rate_bps' => $this->commission_rate_bps,
            'commission_rate_percent' => $this->commissionRatePercent(),
            'monthly_target_paise' => $this->monthly_target_paise,
            'language' => $this->user->language,
            'enable_notification' => $this->user->enable_notification,
        ];
    }
}
