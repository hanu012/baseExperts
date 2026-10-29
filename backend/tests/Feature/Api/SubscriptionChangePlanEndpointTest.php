<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Commission;
use App\Models\Media;
use App\Models\Plan;
use App\Models\PlanQuota;
use App\Models\Salesman;
use App\Models\Subcategory;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST /api/subscriptions/{subscription}/change-plan (SPEC section 3
 * item 6 / section 6, task 4.7) — upgrade/downgrade, one shared code
 * path per decision 5's design.
 */
class SubscriptionChangePlanEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function salesmanUser(int $commissionRateBps = 1000): User
    {
        $user = User::factory()->role(UserRole::Salesman)->create(['must_change_password' => false]);

        Salesman::create([
            'user_id' => $user->id,
            'employee_code' => 'EMP-'.fake()->unique()->numberBetween(1000, 9999),
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'commission_rate_bps' => $commissionRateBps,
        ]);

        return $user->fresh();
    }

    private function plan(int $pricePaise, int $durationDays, array $quotaOverrides = []): Plan
    {
        $plan = Plan::factory()->create(['price_paise' => $pricePaise, 'duration_days' => $durationDays]);
        PlanQuota::where('plan_id', $plan->id)->update(array_merge([
            'max_categories' => 5, 'max_subcategories' => 15, 'max_zones' => 5,
            'max_photos' => 5, 'max_videos' => 2,
        ], $quotaOverrides));

        return $plan->fresh(['quota']);
    }

    /**
     * @return array{0: Category, 1: Subcategory}
     */
    private function categoryWithSubcategory(): array
    {
        $category = Category::factory()->create();

        return [$category, Subcategory::factory()->for($category)->create()];
    }

    private function leafZone(): Zone
    {
        return Zone::factory()->active()->create();
    }

    /**
     * An active vendor + active subscription with a known price,
     * duration and end_date so proration is exactly predictable —
     * `pricePaise=10000, durationDays=100, unusedDays=50` means
     * `remaining_value = 10000 * 50 / 100 = 5000`.
     */
    private function activeVendorWithSubscription(
        Plan $plan,
        ?Salesman $salesman = null,
        int $durationDays = 100,
        int $unusedDays = 50,
    ): array {
        $vendorUser = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = Vendor::create([
            'user_id' => $vendorUser->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
            'created_by_salesman_id' => $salesman?->id,
        ]);

        $subscription = Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'salesman_id' => $salesman?->id,
            'source' => $salesman ? 'salesman' : 'self',
            'status' => 'active',
            'start_date' => now()->subDays($durationDays - $unusedDays)->startOfDay(),
            'end_date' => now()->startOfDay()->addDays($unusedDays),
            'price_paise' => $plan->price_paise,
            'duration_days' => $durationDays,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        return [$vendorUser, $vendor, $subscription];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Plan $newPlan, Category $category, Subcategory $subcategory, Zone $zone, array $overrides = []): array
    {
        return array_merge([
            'plan_id' => $newPlan->id,
            'category_ids' => [$category->id],
            'subcategory_ids' => [$subcategory->id],
            'zone_ids' => [$zone->id],
            'payment_mode' => 'cash',
        ], $overrides);
    }

    private function idempotencyKey(): string
    {
        return (string) Str::uuid();
    }

    // ── Proration (decision 1) ──────────────────────────────────────────

    public function test_upgrade_credits_unused_days_and_charges_the_difference(): void
    {
        $salesman = $this->salesmanUser();
        $oldPlan = $this->plan(pricePaise: 10_000, durationDays: 100);
        [$user, , $subscription] = $this->activeVendorWithSubscription($oldPlan, $salesman->salesman, durationDays: 100, unusedDays: 50);
        $newPlan = $this->plan(pricePaise: 20_000, durationDays: 30);
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        // remaining_value = 10000 * 50 / 100 = 5000; amount_due = 20000 - 5000 = 15000.
        $response = $this->actingAs($salesman, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/change-plan",
                $this->payload($newPlan, $category, $subcategory, $zone),
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertCreated();

        $response->assertJsonPath('data.subscription.price_paise', 15_000);
        $response->assertJsonPath('data.subscription.duration_days', 30);
        $response->assertJsonPath('data.previous_subscription.status', 'superseded');

        $newSubscriptionId = $response->json('data.subscription.id');
        $this->assertDatabaseHas('subscriptions', [
            'id' => $newSubscriptionId,
            'previous_subscription_id' => $subscription->id,
            'price_paise' => 15_000,
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => 'superseded',
        ]);
        $this->assertSame(now()->startOfDay()->toDateString(), $subscription->fresh()->end_date->toDateString());
    }

    public function test_downgrade_never_goes_below_zero_and_issues_no_refund(): void
    {
        $oldPlan = $this->plan(pricePaise: 50_000, durationDays: 100);
        [$user, , $subscription] = $this->activeVendorWithSubscription($oldPlan, null, durationDays: 100, unusedDays: 50);
        // remaining_value = 50000 * 50 / 100 = 25000, comfortably more than the new plan's price.
        $newPlan = $this->plan(pricePaise: 10_000, durationDays: 30);
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/change-plan",
                $this->payload($newPlan, $category, $subcategory, $zone, ['payment_mode' => 'online']),
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertCreated();

        $response->assertJsonPath('data.subscription.price_paise', 0);

        $newSubscriptionId = $response->json('data.subscription.id');
        $this->assertDatabaseHas('payments', ['subscription_id' => $newSubscriptionId, 'amount_paise' => 0]);
    }

    public function test_commission_is_computed_off_the_discounted_amount_not_the_plans_list_price(): void
    {
        $salesman = $this->salesmanUser(commissionRateBps: 1000); // 10%
        $oldPlan = $this->plan(pricePaise: 10_000, durationDays: 100);
        [, , $subscription] = $this->activeVendorWithSubscription($oldPlan, $salesman->salesman, durationDays: 100, unusedDays: 50);
        $newPlan = $this->plan(pricePaise: 20_000, durationDays: 30);
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $response = $this->actingAs($salesman, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/change-plan",
                $this->payload($newPlan, $category, $subcategory, $zone),
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertCreated();

        $newSubscriptionId = $response->json('data.subscription.id');
        // amount_due = 15000, 10% = 1500 — not 20000 * 10% = 2000.
        $this->assertDatabaseHas('commissions', [
            'subscription_id' => $newSubscriptionId,
            'amount_paise' => 1_500,
        ]);
    }

    public function test_an_expired_subscriptions_credit_floors_at_zero_unused_days(): void
    {
        $oldPlan = $this->plan(pricePaise: 10_000, durationDays: 30);
        [$user, , $subscription] = $this->activeVendorWithSubscription($oldPlan, null, durationDays: 30, unusedDays: 0);
        $subscription->update(['end_date' => now()->subDays(5)]); // already past end_date
        $newPlan = $this->plan(pricePaise: 5_000, durationDays: 30);
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/change-plan",
                $this->payload($newPlan, $category, $subcategory, $zone, ['payment_mode' => 'online']),
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertCreated();

        // No credit at all — full price charged.
        $response->assertJsonPath('data.subscription.price_paise', 5_000);
    }

    // ── Downgrade blocking (decision 5) ─────────────────────────────────

    public function test_downgrade_is_blocked_when_too_many_categories_are_selected(): void
    {
        $oldPlan = $this->plan(pricePaise: 20_000, durationDays: 100);
        [$user, , $subscription] = $this->activeVendorWithSubscription($oldPlan, null, durationDays: 100, unusedDays: 50);
        $newPlan = $this->plan(pricePaise: 5_000, durationDays: 30, quotaOverrides: ['max_categories' => 1]);
        [$c1] = $this->categoryWithSubcategory();
        [$c2, $s2] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/change-plan",
                $this->payload($newPlan, $c1, $s2, $zone, [
                    'category_ids' => [$c1->id, $c2->id],
                    'subcategory_ids' => [$s2->id],
                    'payment_mode' => 'online',
                ]),
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['category_ids']]]);
    }

    public function test_downgrade_is_blocked_when_existing_photo_usage_exceeds_the_new_plans_limit(): void
    {
        $oldPlan = $this->plan(pricePaise: 20_000, durationDays: 100, quotaOverrides: ['max_photos' => 10]);
        [$user, $vendor, $subscription] = $this->activeVendorWithSubscription($oldPlan, null, durationDays: 100, unusedDays: 50);

        for ($i = 0; $i < 3; $i++) {
            $vendor->media()->create([
                'disk' => 'public', 'path' => "photo-{$i}.jpg", 'type' => 'image',
                'size_bytes' => 100, 'moderation_status' => 'approved',
            ]);
        }

        $newPlan = $this->plan(pricePaise: 5_000, durationDays: 30, quotaOverrides: ['max_photos' => 2]);
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/change-plan",
                $this->payload($newPlan, $category, $subcategory, $zone, ['payment_mode' => 'online']),
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['plan_id']]]);
    }

    public function test_downgrade_succeeds_when_photo_usage_fits_the_new_plans_limit(): void
    {
        $oldPlan = $this->plan(pricePaise: 20_000, durationDays: 100, quotaOverrides: ['max_photos' => 10]);
        [$user, $vendor, $subscription] = $this->activeVendorWithSubscription($oldPlan, null, durationDays: 100, unusedDays: 50);

        $vendor->media()->create([
            'disk' => 'public', 'path' => 'photo.jpg', 'type' => 'image',
            'size_bytes' => 100, 'moderation_status' => 'approved',
        ]);

        $newPlan = $this->plan(pricePaise: 5_000, durationDays: 30, quotaOverrides: ['max_photos' => 2]);
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/change-plan",
                $this->payload($newPlan, $category, $subcategory, $zone, ['payment_mode' => 'online']),
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertCreated();
    }

    /**
     * Add-ons don't carry forward across a plan change (decision 2) — a
     * downgrade is blocked by the NEW plan's bare limit even though the
     * OLD subscription had purchased extra photo quota, since that
     * add-on quantity is scoped to the old subscription and won't
     * transfer to the new one.
     */
    public function test_a_purchased_addon_on_the_old_subscription_does_not_prevent_downgrade_blocking(): void
    {
        $oldPlan = $this->plan(pricePaise: 20_000, durationDays: 100, quotaOverrides: [
            'max_photos' => 1,
            'addon_price_per_photo_paise' => 100,
        ]);
        [$user, $vendor, $subscription] = $this->activeVendorWithSubscription($oldPlan, null, durationDays: 100, unusedDays: 50);

        // Bought +2 photo slots on the OLD subscription (bare max 1 -> effective 3).
        \App\Models\SubscriptionAddon::create([
            'subscription_id' => $subscription->id,
            'resource' => 'photos',
            'quantity' => 2,
            'price_paise' => 200,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        for ($i = 0; $i < 3; $i++) {
            $vendor->media()->create([
                'disk' => 'public', 'path' => "photo-{$i}.jpg", 'type' => 'image',
                'size_bytes' => 100, 'moderation_status' => 'approved',
            ]);
        }

        $newPlan = $this->plan(pricePaise: 5_000, durationDays: 30, quotaOverrides: ['max_photos' => 2]);
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        // 3 photos used, new plan's BARE max is 2 — blocked even though
        // the old subscription's effective (addon-expanded) quota was 3.
        $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/change-plan",
                $this->payload($newPlan, $category, $subcategory, $zone, ['payment_mode' => 'online']),
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['plan_id']]]);
    }

    // ── Old subscription's fate (decision 6) ────────────────────────────

    public function test_the_old_subscription_ends_up_superseded_not_cancelled(): void
    {
        $oldPlan = $this->plan(pricePaise: 10_000, durationDays: 100);
        [$user, , $subscription] = $this->activeVendorWithSubscription($oldPlan, null, durationDays: 100, unusedDays: 50);
        $newPlan = $this->plan(pricePaise: 20_000, durationDays: 30);
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/change-plan",
                $this->payload($newPlan, $category, $subcategory, $zone, ['payment_mode' => 'online']),
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertCreated();

        $this->assertSame('superseded', $subscription->fresh()->status);
        $this->assertNotSame('cancelled', $subscription->fresh()->status);
    }

    // ── Role / ownership parity with StoreSubscriptionRequest ──────────

    public function test_a_salesman_cannot_change_a_plan_for_a_vendor_they_did_not_add(): void
    {
        $salesman = $this->salesmanUser();
        $otherSalesman = $this->salesmanUser();
        $oldPlan = $this->plan(pricePaise: 10_000, durationDays: 100);
        [, , $subscription] = $this->activeVendorWithSubscription($oldPlan, $otherSalesman->salesman, durationDays: 100, unusedDays: 50);
        $newPlan = $this->plan(pricePaise: 20_000, durationDays: 30);
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($salesman, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/change-plan",
                $this->payload($newPlan, $category, $subcategory, $zone),
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['subscription']]]);
    }

    public function test_a_vendor_cannot_change_another_vendors_subscription(): void
    {
        $oldPlan = $this->plan(pricePaise: 10_000, durationDays: 100);
        [, , $subscription] = $this->activeVendorWithSubscription($oldPlan, null, durationDays: 100, unusedDays: 50);
        $otherVendorUser = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        Vendor::create([
            'user_id' => $otherVendorUser->id, 'business_name' => 'Other', 'owner_name' => 'Someone',
            'phone' => '9812340099', 'status' => 'active',
        ]);
        $newPlan = $this->plan(pricePaise: 20_000, durationDays: 30);
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($otherVendorUser, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/change-plan",
                $this->payload($newPlan, $category, $subcategory, $zone, ['payment_mode' => 'online']),
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['subscription']]]);
    }

    public function test_a_vendor_is_restricted_to_payment_mode_online(): void
    {
        $oldPlan = $this->plan(pricePaise: 10_000, durationDays: 100);
        [$user, , $subscription] = $this->activeVendorWithSubscription($oldPlan, null, durationDays: 100, unusedDays: 50);
        $newPlan = $this->plan(pricePaise: 20_000, durationDays: 30);
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/subscriptions/{$subscription->id}/change-plan",
                $this->payload($newPlan, $category, $subcategory, $zone, ['payment_mode' => 'cash']),
                ['Idempotency-Key' => $this->idempotencyKey()],
            )
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['payment_mode']]]);
    }

    // ── Idempotency ──────────────────────────────────────────────────────

    public function test_replaying_the_same_idempotency_key_returns_the_same_new_subscription(): void
    {
        $oldPlan = $this->plan(pricePaise: 10_000, durationDays: 100);
        [$user, , $subscription] = $this->activeVendorWithSubscription($oldPlan, null, durationDays: 100, unusedDays: 50);
        $newPlan = $this->plan(pricePaise: 20_000, durationDays: 30);
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();
        $key = $this->idempotencyKey();

        $first = $this->actingAs($user, 'sanctum')->postJson(
            "/api/subscriptions/{$subscription->id}/change-plan",
            $this->payload($newPlan, $category, $subcategory, $zone, ['payment_mode' => 'online']),
            ['Idempotency-Key' => $key],
        )->assertCreated();

        $second = $this->actingAs($user, 'sanctum')->postJson(
            "/api/subscriptions/{$subscription->id}/change-plan",
            $this->payload($newPlan, $category, $subcategory, $zone, ['payment_mode' => 'online']),
            ['Idempotency-Key' => $key],
        )->assertOk();

        $this->assertSame($first->json('data.subscription.id'), $second->json('data.subscription.id'));
        $this->assertSame(1, Subscription::where('previous_subscription_id', $subscription->id)->count());
    }
}
