<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Commission;
use App\Models\Plan;
use App\Models\Salesman;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET/PATCH /api/salesmen/me (SPEC section 2.5).
 *
 * Until these existed the app had no way to show a salesman their own
 * employee code, phone, region, target or commission rate — all of it was
 * admin-panel-only.
 */
class SalesmanProfileEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function salesman(array $overrides = []): Salesman
    {
        $user = User::factory()->role(UserRole::Salesman)->create(['name' => 'Ramprakash']);

        return Salesman::create(array_merge([
            'user_id' => $user->id,
            'employee_code' => 'SM-'.fake()->unique()->numberBetween(1000, 9999),
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'region' => 'Ahmedabad · Gujarat',
            'monthly_target_paise' => 10000000, // ₹1,00,000
            'commission_rate_bps' => 1200,
            'is_active' => true,
        ], $overrides));
    }

    private function vendorFor(Salesman $salesman, string $status = 'active'): Vendor
    {
        $user = User::factory()->role(UserRole::Vendor)->create();

        return Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Shop '.fake()->unique()->numberBetween(1, 999999),
            'owner_name' => 'Owner',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => $status,
            'created_by_salesman_id' => $salesman->id,
        ]);
    }

    private function subscribe(Vendor $vendor, Salesman $salesman, int $pricePaise, ?string $createdAt = null): Subscription
    {
        $plan = Plan::factory()->create();

        $subscription = Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'salesman_id' => $salesman->id,
            'source' => 'salesman',
            'status' => 'active',
            'start_date' => now()->subDay(),
            'end_date' => now()->addDays(300),
            'price_paise' => $pricePaise,
            'duration_days' => $plan->duration_days,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        if ($createdAt !== null) {
            // Passing created_at to create() is silently overwritten by
            // Eloquent's own timestamping, so backdate it afterwards —
            // through the query builder, which does not re-touch it.
            Subscription::query()->whereKey($subscription->id)->update(['created_at' => $createdAt]);
        }

        return $subscription;
    }

    public function test_it_returns_the_salesmans_own_identity_and_preferences(): void
    {
        $salesman = $this->salesman();

        $this->actingAs($salesman->user)
            ->getJson('/api/salesmen/me')
            ->assertOk()
            ->assertJsonPath('data.salesman.name', 'Ramprakash')
            ->assertJsonPath('data.salesman.employee_code', $salesman->employee_code)
            ->assertJsonPath('data.salesman.region', 'Ahmedabad · Gujarat')
            // Formatted server-side so the app can never round it into
            // disagreeing with the actual payout rate.
            ->assertJsonPath('data.salesman.commission_rate_percent', '12.00')
            ->assertJsonPath('data.salesman.language', 'en')
            ->assertJsonPath('data.salesman.enable_notification', true);
    }

    public function test_stats_count_vendors_and_separate_paid_from_pending_earnings(): void
    {
        $salesman = $this->salesman();

        $subscribed = $this->vendorFor($salesman);
        $this->subscribe($subscribed, $salesman, 500000);
        $this->vendorFor($salesman, 'draft');

        Commission::create([
            'subscription_id' => $subscribed->subscriptions()->first()->id,
            'salesman_id' => $salesman->id,
            'amount_paise' => 60000,
            'rate_bps' => 1200,
            'status' => 'paid',
        ]);

        $this->actingAs($salesman->user)
            ->getJson('/api/salesmen/me')
            ->assertOk()
            ->assertJsonPath('data.stats.total_vendors', 2)
            // Only the one with a live subscription counts as subscribed.
            ->assertJsonPath('data.stats.subscribed_vendors', 1)
            ->assertJsonPath('data.stats.earnings_paise', 60000)
            ->assertJsonPath('data.stats.pending_earnings_paise', 0);
    }

    /**
     * Target progress is revenue SOLD, not commission earned — a salesman
     * on 12% would otherwise read as 12% of target after selling all of it.
     */
    public function test_target_progress_measures_revenue_sold_this_month(): void
    {
        $salesman = $this->salesman(['monthly_target_paise' => 1000000]);

        $vendor = $this->vendorFor($salesman);
        $this->subscribe($vendor, $salesman, 250000);

        // Last month's sale must not count toward this month's target.
        $old = $this->vendorFor($salesman);
        $this->subscribe($old, $salesman, 900000, now()->subMonthNoOverflow()->startOfMonth()->toDateTimeString());

        $this->actingAs($salesman->user)
            ->getJson('/api/salesmen/me')
            ->assertOk()
            ->assertJsonPath('data.stats.target.monthly_target_paise', 1000000)
            ->assertJsonPath('data.stats.target.achieved_paise', 250000)
            ->assertJsonPath('data.stats.target.percent', 25);
    }

    public function test_percent_is_null_when_no_target_is_set(): void
    {
        // "No target" and "0% of a target" are different states: the app
        // hides the bar for the former rather than drawing an empty one.
        $salesman = $this->salesman(['monthly_target_paise' => 0]);

        $this->actingAs($salesman->user)
            ->getJson('/api/salesmen/me')
            ->assertOk()
            ->assertJsonPath('data.stats.target.percent', null);
    }

    public function test_percent_is_capped_at_one_hundred(): void
    {
        $salesman = $this->salesman(['monthly_target_paise' => 100000]);
        $vendor = $this->vendorFor($salesman);
        $this->subscribe($vendor, $salesman, 500000);

        $this->actingAs($salesman->user)
            ->getJson('/api/salesmen/me')
            ->assertOk()
            ->assertJsonPath('data.stats.target.percent', 100);
    }

    public function test_a_salesman_can_update_their_name_and_phone(): void
    {
        $salesman = $this->salesman();

        $this->actingAs($salesman->user)
            ->patchJson('/api/salesmen/me', ['name' => 'Ram Prakash', 'phone' => '9876500011'])
            ->assertOk()
            ->assertJsonPath('data.salesman.name', 'Ram Prakash')
            ->assertJsonPath('data.salesman.phone', '9876500011');

        $this->assertSame('Ram Prakash', $salesman->user->fresh()->name);
        $this->assertSame('9876500011', $salesman->fresh()->phone);
    }

    public function test_one_field_can_be_sent_without_the_other(): void
    {
        $salesman = $this->salesman();
        $originalPhone = $salesman->phone;

        $this->actingAs($salesman->user)
            ->patchJson('/api/salesmen/me', ['name' => 'Only Name'])
            ->assertOk();

        $this->assertSame($originalPhone, $salesman->fresh()->phone);
    }

    public function test_resubmitting_an_unchanged_phone_is_not_a_duplicate(): void
    {
        // salesmen.phone is uniquely indexed, so the rule must ignore this
        // row or saving the profile twice would fail the second time.
        $salesman = $this->salesman();

        $this->actingAs($salesman->user)
            ->patchJson('/api/salesmen/me', ['phone' => $salesman->phone])
            ->assertOk();
    }

    public function test_another_salesmans_phone_is_rejected(): void
    {
        $taken = $this->salesman();
        $salesman = $this->salesman();

        $this->actingAs($salesman->user)
            ->patchJson('/api/salesmen/me', ['phone' => $taken->phone])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.phone.0', fn (string $message): bool => $message !== '');
    }

    /**
     * Email, employee code, region, target and commission rate are all
     * admin-owned. A salesman raising their own commission rate is the
     * obvious reason this must not be a mass-assign.
     */
    public function test_organisational_fields_cannot_be_changed_by_the_salesman(): void
    {
        $salesman = $this->salesman();

        $this->actingAs($salesman->user)
            ->patchJson('/api/salesmen/me', [
                'name' => 'Fine',
                'employee_code' => 'HACKED',
                'region' => 'Everywhere',
                'commission_rate_bps' => 9999,
                'monthly_target_paise' => 1,
                'email' => 'new@example.com',
            ])
            ->assertOk();

        $fresh = $salesman->fresh();

        $this->assertNotSame('HACKED', $fresh->employee_code);
        $this->assertSame('Ahmedabad · Gujarat', $fresh->region);
        $this->assertSame(1200, $fresh->commission_rate_bps);
        $this->assertSame(10000000, $fresh->monthly_target_paise);
        $this->assertNotSame('new@example.com', $fresh->user->email);
    }

    public function test_a_vendor_cannot_reach_the_salesman_profile(): void
    {
        $vendor = User::factory()->role(UserRole::Vendor)->create();

        $this->actingAs($vendor)->getJson('/api/salesmen/me')->assertStatus(403);
        $this->actingAs($vendor)->patchJson('/api/salesmen/me', ['name' => 'x'])->assertStatus(403);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/salesmen/me')->assertStatus(401);
    }
}
