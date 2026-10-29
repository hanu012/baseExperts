<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanQuota;
use App\Models\Salesman;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST /api/subscriptions/{subscription}/add-ons (SPEC section 3 item 6
 * / section 6, task 4.7) — quota purchased on top of the base plan.
 */
class SubscriptionAddOnEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function salesmanUser(): User
    {
        $user = User::factory()->role(UserRole::Salesman)->create(['must_change_password' => false]);

        Salesman::create([
            'user_id' => $user->id,
            'employee_code' => 'EMP-'.fake()->unique()->numberBetween(1000, 9999),
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'commission_rate_bps' => 1000,
        ]);

        return $user->fresh();
    }

    private function activeVendorWithSubscription(?Salesman $salesman = null, int $addonPricePerCategory = 500): array
    {
        $vendorUser = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = Vendor::create([
            'user_id' => $vendorUser->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
            'created_by_salesman_id' => $salesman?->id,
        ]);

        $plan = Plan::factory()->create(['price_paise' => 10_000, 'duration_days' => 100]);
        PlanQuota::where('plan_id', $plan->id)->update([
            'max_categories' => 3, 'max_subcategories' => 6, 'max_zones' => 3,
            'max_photos' => 5, 'max_videos' => 2,
            'addon_price_per_category_paise' => $addonPricePerCategory,
        ]);

        $subscription = Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'salesman_id' => $salesman?->id,
            'source' => $salesman ? 'salesman' : 'self',
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addDays(50),
            'price_paise' => $plan->price_paise,
            'duration_days' => 100,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        return [$vendorUser, $vendor, $subscription->fresh(['plan.quota'])];
    }

    private function idempotencyKey(): string
    {
        return (string) Str::uuid();
    }

    public function test_a_purchase_computes_price_from_the_plans_unit_price_times_quantity(): void
    {
        [$user, , $subscription] = $this->activeVendorWithSubscription(addonPricePerCategory: 500);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/add-ons",
                ['resource' => 'categories', 'quantity' => 3, 'payment_mode' => 'online'],
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertCreated();

        $response->assertJsonPath('data.addon.resource', 'categories');
        $response->assertJsonPath('data.addon.quantity', 3);
        $response->assertJsonPath('data.addon.price_paise', 1_500); // 500 * 3

        $this->assertDatabaseHas('payments', [
            'subscription_id' => $subscription->id,
            'amount_paise' => 1_500,
        ]);
    }

    public function test_client_sent_price_is_ignored_entirely(): void
    {
        [$user, , $subscription] = $this->activeVendorWithSubscription(addonPricePerCategory: 500);

        $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/add-ons",
                ['resource' => 'categories', 'quantity' => 2, 'price_paise' => 1, 'payment_mode' => 'online'],
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertCreated()
            ->assertJsonPath('data.addon.price_paise', 1_000); // 500 * 2, not the client's 1
    }

    public function test_a_second_purchase_accumulates_and_effective_quota_reflects_both(): void
    {
        [$user, , $subscription] = $this->activeVendorWithSubscription(addonPricePerCategory: 500);

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/subscriptions/{$subscription->id}/add-ons",
            ['resource' => 'categories', 'quantity' => 1, 'payment_mode' => 'online'],
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertCreated();

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/subscriptions/{$subscription->id}/add-ons",
            ['resource' => 'categories', 'quantity' => 2, 'payment_mode' => 'online'],
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertCreated();

        // Bare max was 3, +1 +2 addons = effective 6.
        $this->assertSame(6, $subscription->fresh()->effectiveQuota('categories'));
    }

    /**
     * `commissions.subscription_id` is UNIQUE — a subscription that
     * already earned a commission on its original sale must not have a
     * second Commission row created against it by an add-on purchase,
     * or the insert would throw a constraint violation. This is the
     * real collision this test proves doesn't happen, not just "no
     * commission was recorded when none existed to begin with."
     */
    public function test_no_second_commission_is_recorded_when_the_subscription_already_has_one(): void
    {
        $salesman = $this->salesmanUser();
        [, , $subscription] = $this->activeVendorWithSubscription($salesman->salesman, addonPricePerCategory: 500);

        \App\Models\Commission::create([
            'subscription_id' => $subscription->id,
            'salesman_id' => $salesman->salesman->id,
            'amount_paise' => 1_000,
            'rate_bps' => 1000,
            'status' => 'pending',
        ]);

        $this->actingAs($salesman, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/add-ons",
                ['resource' => 'categories', 'quantity' => 1, 'payment_mode' => 'cash'],
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertCreated();

        $this->assertSame(1, \App\Models\Commission::where('subscription_id', $subscription->id)->count());
    }

    // ── Role / ownership parity ──────────────────────────────────────────

    public function test_a_salesman_cannot_buy_addons_for_a_vendor_they_did_not_add(): void
    {
        $salesman = $this->salesmanUser();
        $otherSalesman = $this->salesmanUser();
        [, , $subscription] = $this->activeVendorWithSubscription($otherSalesman->salesman);

        $this->actingAs($salesman, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/add-ons",
                ['resource' => 'categories', 'quantity' => 1, 'payment_mode' => 'cash'],
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['subscription']]]);
    }

    public function test_a_vendor_cannot_buy_addons_for_another_vendor(): void
    {
        [, , $subscription] = $this->activeVendorWithSubscription();
        $otherVendorUser = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        Vendor::create([
            'user_id' => $otherVendorUser->id, 'business_name' => 'Other', 'owner_name' => 'Someone',
            'phone' => '9812340099', 'status' => 'active',
        ]);

        $this->actingAs($otherVendorUser, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/add-ons",
                ['resource' => 'categories', 'quantity' => 1, 'payment_mode' => 'online'],
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['subscription']]]);
    }

    public function test_a_vendor_is_restricted_to_payment_mode_online(): void
    {
        [$user, , $subscription] = $this->activeVendorWithSubscription();

        $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/add-ons",
                ['resource' => 'categories', 'quantity' => 1, 'payment_mode' => 'cash'],
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['payment_mode']]]);
    }

    public function test_free_is_not_an_allowed_payment_mode(): void
    {
        [$user, , $subscription] = $this->activeVendorWithSubscription();

        $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/add-ons",
                ['resource' => 'categories', 'quantity' => 1, 'payment_mode' => 'free'],
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['payment_mode']]]);
    }

    // ── Idempotency ──────────────────────────────────────────────────────

    public function test_a_missing_idempotency_key_is_rejected(): void
    {
        [$user, , $subscription] = $this->activeVendorWithSubscription();

        $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/add-ons",
                ['resource' => 'categories', 'quantity' => 1, 'payment_mode' => 'online'],
            )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    public function test_replaying_the_same_idempotency_key_does_not_charge_twice(): void
    {
        [$user, , $subscription] = $this->activeVendorWithSubscription(addonPricePerCategory: 500);
        $key = $this->idempotencyKey();

        $first = $this->actingAs($user, 'sanctum')->postJson(
            "/api/subscriptions/{$subscription->id}/add-ons",
            ['resource' => 'categories', 'quantity' => 2, 'payment_mode' => 'online'],
            ['Idempotency-Key' => $key],
        )->assertCreated();

        $second = $this->actingAs($user, 'sanctum')->postJson(
            "/api/subscriptions/{$subscription->id}/add-ons",
            ['resource' => 'categories', 'quantity' => 2, 'payment_mode' => 'online'],
            ['Idempotency-Key' => $key],
        )->assertOk();

        $this->assertSame($first->json('data.addon.id'), $second->json('data.addon.id'));
        $this->assertSame(1, $subscription->addons()->count());
        $this->assertSame(1, $subscription->payments()->count());
    }
}
