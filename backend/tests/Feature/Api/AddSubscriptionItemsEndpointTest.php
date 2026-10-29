<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Plan;
use App\Models\PlanQuota;
use App\Models\Subcategory;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST /api/vendors/me/services (SPEC section 3.3, task 4.4) — adding
 * within remaining quota on the vendor's own already-active subscription,
 * distinct from POST /subscriptions (SubscriptionEndpointTest), which only
 * ever creates a fresh subscription for a `draft` vendor.
 */
class AddSubscriptionItemsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function planWithQuota(int $maxCategories = 3, int $maxSubcategories = 6, int $maxZones = 3): Plan
    {
        $plan = Plan::factory()->create(['price_paise' => 99_900, 'duration_days' => 365]);
        PlanQuota::where('plan_id', $plan->id)->update([
            'max_categories' => $maxCategories,
            'max_subcategories' => $maxSubcategories,
            'max_zones' => $maxZones,
        ]);

        return $plan->fresh(['quota']);
    }

    /**
     * @return array{0: Category, 1: Subcategory}
     */
    private function categoryWithSubcategory(): array
    {
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->for($category)->create();

        return [$category, $subcategory];
    }

    private function leafZone(): Zone
    {
        return Zone::factory()->active()->create();
    }

    /**
     * @return array{0: Zone, 1: Zone} parent, child
     */
    private function zoneWithChild(): array
    {
        $parent = Zone::factory()->active()->create();
        $child = Zone::factory()->active()->create(['parent_id' => $parent->id]);

        return [$parent, $child];
    }

    /**
     * An active vendor with an active subscription already carrying one
     * category/subcategory/zone, on a plan with room for more.
     *
     * @return array{0: User, 1: Vendor, 2: Subscription, 3: Category, 4: Subcategory, 5: Zone}
     */
    private function activeVendorWithSubscription(
        int $maxCategories = 3,
        int $maxSubcategories = 6,
        int $maxZones = 3,
    ): array {
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ]);

        $plan = $this->planWithQuota($maxCategories, $maxSubcategories, $maxZones);
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $subscription = Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addDays(300),
            'price_paise' => $plan->price_paise,
            'duration_days' => $plan->duration_days,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        SubscriptionItem::insert([
            ['subscription_id' => $subscription->id, 'item_type' => 'category', 'item_id' => $category->id, 'created_at' => now(), 'updated_at' => now()],
            ['subscription_id' => $subscription->id, 'item_type' => 'subcategory', 'item_id' => $subcategory->id, 'created_at' => now(), 'updated_at' => now()],
            ['subscription_id' => $subscription->id, 'item_type' => 'zone', 'item_id' => $zone->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        return [$user, $vendor, $subscription->fresh(), $category, $subcategory, $zone];
    }

    // ── Happy path ───────────────────────────────────────────────────────

    public function test_a_vendor_can_add_within_remaining_quota(): void
    {
        [$user, , $subscription] = $this->activeVendorWithSubscription(maxCategories: 3);
        [$newCategory, $newSubcategory] = $this->categoryWithSubcategory();
        $newZone = $this->leafZone();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/services', [
                'category_ids' => [$newCategory->id],
                'subcategory_ids' => [$newSubcategory->id],
                'zone_ids' => [$newZone->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.active_subscription.quota.categories.used', 2)
            ->assertJsonPath('data.active_subscription.quota.subcategories.used', 2)
            ->assertJsonPath('data.active_subscription.quota.zones.used', 2);

        $this->assertSame(6, $subscription->items()->count());
    }

    public function test_resubmitting_an_already_selected_id_is_a_no_op(): void
    {
        [$user, , $subscription, $category, $subcategory, $zone] = $this->activeVendorWithSubscription();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/services', [
                'category_ids' => [$category->id],
                'subcategory_ids' => [$subcategory->id],
                'zone_ids' => [$zone->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.active_subscription.quota.categories.used', 1);

        $this->assertSame(3, $subscription->items()->count());
    }

    public function test_an_empty_submission_succeeds_as_a_no_op(): void
    {
        [$user] = $this->activeVendorWithSubscription();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/services', [])
            ->assertOk();
    }

    // ── Quota ────────────────────────────────────────────────────────────

    public function test_exceeding_remaining_quota_is_rejected_even_under_the_plans_absolute_max(): void
    {
        // Plan allows 3 categories total; 1 already used, so 2 remain.
        // Submitting 3 new ones exceeds what's REMAINING even though
        // 1 (existing) + 3 (new) = 4 also simply exceeds the absolute max —
        // the point is the error is computed off "remaining", not
        // "count(submitted) > max" the way subscribe's check is.
        [$user] = $this->activeVendorWithSubscription(maxCategories: 3);
        [$c1] = $this->categoryWithSubcategory();
        [$c2] = $this->categoryWithSubcategory();
        [$c3] = $this->categoryWithSubcategory();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/services', [
                'category_ids' => [$c1->id, $c2->id, $c3->id],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['category_ids']]]);
    }

    /**
     * A purchased category add-on (task 4.7) expands what this
     * remaining-quota check honors too, not just VendorPortfolioController.
     */
    public function test_a_purchased_addon_expands_the_remaining_category_quota(): void
    {
        [$user, , $subscription] = $this->activeVendorWithSubscription(maxCategories: 1);
        [$newCategory, $newSubcategory] = $this->categoryWithSubcategory();
        [$secondCategory, $secondSubcategory] = $this->categoryWithSubcategory();

        \App\Models\SubscriptionAddon::create([
            'subscription_id' => $subscription->id,
            'resource' => 'categories',
            'quantity' => 1,
            'price_paise' => 500,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        // Bare max is 1 (already used); the +1 add-on makes a second
        // category fit — without it this would be rejected, same as
        // test_exceeding_remaining_quota_is_rejected_even_under_the_plans_absolute_max.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/services', [
                'category_ids' => [$newCategory->id],
                'subcategory_ids' => [$newSubcategory->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.active_subscription.quota.categories.used', 2);
    }

    public function test_exactly_filling_remaining_quota_succeeds(): void
    {
        // 1 category already used, plan max is 2 — exactly 1 slot remains.
        [$user] = $this->activeVendorWithSubscription(maxCategories: 2);
        [$newCategory] = $this->categoryWithSubcategory();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/services', [
                'category_ids' => [$newCategory->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.active_subscription.quota.categories.used', 2);
    }

    // ── No active subscription ──────────────────────────────────────────

    public function test_a_vendor_with_no_active_subscription_is_rejected(): void
    {
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => '9812345678',
            'status' => 'draft',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/services', ['category_ids' => [1]])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['subscription']]]);
    }

    // ── Role gating ──────────────────────────────────────────────────────

    public function test_a_salesman_cannot_call_this_endpoint(): void
    {
        $salesmanUser = User::factory()->role(UserRole::Salesman)->create(['must_change_password' => false]);

        $this->actingAs($salesmanUser, 'sanctum')
            ->postJson('/api/vendors/me/services', ['category_ids' => [1]])
            ->assertStatus(403);
    }

    // ── Server-side re-verification (never trust the client) ───────────────

    public function test_a_non_leaf_zone_is_rejected(): void
    {
        [$user] = $this->activeVendorWithSubscription();
        [$parent, ] = $this->zoneWithChild();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/services', ['zone_ids' => [$parent->id]])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['zone_ids']]]);
    }

    public function test_a_new_subcategory_may_belong_to_a_previously_selected_category(): void
    {
        // The vendor already has $category (from activeVendorWithSubscription)
        // selected. Adding one of ITS other subcategories, without resubmitting
        // the category itself, must succeed — the parent was selected earlier,
        // not in this request.
        [$user, , , $category] = $this->activeVendorWithSubscription();
        $anotherSubcategory = Subcategory::factory()->for($category)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/services', ['subcategory_ids' => [$anotherSubcategory->id]])
            ->assertOk()
            ->assertJsonPath('data.active_subscription.quota.subcategories.used', 2);
    }

    public function test_a_subcategory_without_any_selected_category_at_all_is_rejected(): void
    {
        [$user] = $this->activeVendorWithSubscription();
        [$otherCategory, $otherSubcategory] = $this->categoryWithSubcategory();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/services', ['subcategory_ids' => [$otherSubcategory->id]])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['subcategory_ids']]]);
    }

    public function test_an_inactive_category_is_rejected(): void
    {
        [$user] = $this->activeVendorWithSubscription();
        $inactiveCategory = Category::factory()->inactive()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/services', ['category_ids' => [$inactiveCategory->id]])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['category_ids']]]);
    }

    // ── Never removes ────────────────────────────────────────────────────

    public function test_omitting_an_existing_item_from_the_submission_does_not_remove_it(): void
    {
        [$user, , $subscription, $category] = $this->activeVendorWithSubscription();
        [$newCategory] = $this->categoryWithSubcategory();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/services', [
                // Submission omits the existing category entirely.
                'category_ids' => [$newCategory->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('subscription_items', [
            'subscription_id' => $subscription->id,
            'item_type' => 'category',
            'item_id' => $category->id,
        ]);
        $this->assertSame(4, $subscription->items()->count());
    }

    // ── Replace mode (the vendor app unticking earlier picks) ─────────────

    public function test_replace_mode_removes_unticked_items_and_adds_new_ones(): void
    {
        [$user, , $subscription, $category, $subcategory, $zone] = $this->activeVendorWithSubscription();
        [$newCategory, $newSubcategory] = $this->categoryWithSubcategory();
        $newZone = $this->leafZone();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/services', [
                'replace' => true,
                'category_ids' => [$newCategory->id],
                'subcategory_ids' => [$newSubcategory->id],
                'zone_ids' => [$newZone->id],
            ])
            ->assertOk();

        foreach ([['category', $category], ['subcategory', $subcategory], ['zone', $zone]] as [$type, $old]) {
            $this->assertDatabaseMissing('subscription_items', [
                'subscription_id' => $subscription->id, 'item_type' => $type, 'item_id' => $old->id,
            ]);
        }

        $this->assertSame(3, $subscription->items()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'services_changed',
            'auditable_id' => $subscription->id,
        ]);
    }

    public function test_replace_mode_checks_the_final_selection_against_the_full_quota(): void
    {
        // One subcategory slot, already used: swapping it for another is fine
        // in replace mode, where additive mode would say "0 remaining".
        [$user, , $subscription, $category] = $this->activeVendorWithSubscription(maxSubcategories: 1);
        $other = Subcategory::factory()->for($category)->create();
        $zone = $subscription->items()->where('item_type', 'zone')->value('item_id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/services', [
                'replace' => true,
                'category_ids' => [$category->id],
                'subcategory_ids' => [$other->id],
                'zone_ids' => [$zone],
            ])
            ->assertOk();

        $this->assertDatabaseHas('subscription_items', [
            'subscription_id' => $subscription->id, 'item_type' => 'subcategory', 'item_id' => $other->id,
        ]);

        $third = Subcategory::factory()->for($category)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/services', [
                'replace' => true,
                'category_ids' => [$category->id],
                'subcategory_ids' => [$other->id, $third->id],
                'zone_ids' => [$zone],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['subcategory_ids']]]);
    }

    public function test_replace_mode_refuses_to_leave_nothing_selected(): void
    {
        [$user, , $subscription] = $this->activeVendorWithSubscription();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/services', [
                'replace' => true,
                'category_ids' => [],
                'subcategory_ids' => [],
                'zone_ids' => [],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['category_ids', 'subcategory_ids', 'zone_ids']]]);

        $this->assertSame(3, $subscription->items()->count());
    }

    public function test_replace_mode_rejects_keeping_a_subcategory_whose_category_was_removed(): void
    {
        [$user, , $subscription, , $subcategory, $zone] = $this->activeVendorWithSubscription();
        [$newCategory] = $this->categoryWithSubcategory();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/services', [
                'replace' => true,
                'category_ids' => [$newCategory->id],
                'subcategory_ids' => [$subcategory->id],
                'zone_ids' => [$zone->id],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['subcategory_ids']]]);

        $this->assertSame(3, $subscription->items()->count());
    }

    // ── Drift detection ──────────────────────────────────────────────────

    /**
     * StoreSubscriptionRequest and AddSubscriptionItemsRequest both delegate
     * their structural checks to ServiceSelectionValidator (task 4.4). This
     * proves they stay in lockstep: the exact same invalid input is rejected
     * identically by both endpoints. If a future edit ever let one of them
     * stop calling the shared class and drift back to its own copy, this is
     * the test that would catch it — it fails because the two responses
     * stop agreeing, not because either endpoint is broken on its own.
     */
    public function test_a_non_leaf_zone_is_rejected_identically_by_subscribe_and_add_services(): void
    {
        [$parent, ] = $this->zoneWithChild();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $leafZone = $this->leafZone();

        // Subscribe: a fresh draft vendor submitting the parent zone.
        $draftVendorUser = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $draftVendor = Vendor::create([
            'user_id' => $draftVendorUser->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => '9812340099',
            'status' => 'draft',
        ]);
        $plan = $this->planWithQuota();

        $subscribeResponse = $this->actingAs($draftVendorUser, 'sanctum')->postJson(
            '/api/subscriptions',
            [
                'vendor_id' => $draftVendor->id,
                'plan_id' => $plan->id,
                'category_ids' => [$category->id],
                'subcategory_ids' => [$subcategory->id],
                'zone_ids' => [$parent->id],
                'payment_mode' => 'online',
            ],
            ['Idempotency-Key' => (string) Str::uuid()],
        );

        // Add-services: an already-active vendor submitting the same parent zone.
        [$activeUser] = $this->activeVendorWithSubscription();

        $addServicesResponse = $this->actingAs($activeUser, 'sanctum')
            ->postJson('/api/vendors/me/services', ['zone_ids' => [$parent->id]]);

        $subscribeResponse->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['zone_ids']]]);
        $addServicesResponse->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['zone_ids']]]);
        $this->assertSame(
            $subscribeResponse->json('error.fields.zone_ids'),
            $addServicesResponse->json('error.fields.zone_ids'),
        );

        // A leaf zone, meanwhile, must be accepted by both.
        $this->assertNotSame(422, $this->actingAs($draftVendorUser, 'sanctum')->postJson(
            '/api/subscriptions',
            [
                'vendor_id' => $draftVendor->id,
                'plan_id' => $plan->id,
                'category_ids' => [$category->id],
                'subcategory_ids' => [$subcategory->id],
                'zone_ids' => [$leafZone->id],
                'payment_mode' => 'online',
            ],
            ['Idempotency-Key' => (string) Str::uuid()],
        )->getStatusCode());
    }
}
