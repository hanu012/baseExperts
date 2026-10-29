<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanQuota;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /api/vendors/me (SPEC section 3.2) — the vendor app's own record,
 * including whether it already has an active subscription (what task
 * 4.2's plan-selection-vs-dashboard branch checks).
 */
class VendorMeEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function vendorUser(): User
    {
        return User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
    }

    public function test_it_requires_a_vendor_token(): void
    {
        $salesmanUser = User::factory()->role(UserRole::Salesman)->create(['must_change_password' => false]);

        $this->actingAs($salesmanUser, 'sanctum')
            ->getJson('/api/vendors/me')
            ->assertStatus(403);
    }

    public function test_it_404s_when_no_vendor_profile_exists(): void
    {
        $user = $this->vendorUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/vendors/me')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_it_returns_the_callers_own_vendor(): void
    {
        $user = $this->vendorUser();
        Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => '9812345678',
            'status' => 'draft',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/vendors/me')
            ->assertOk()
            ->assertJsonPath('data.vendor.business_name', 'Cool Air Services')
            ->assertJsonPath('data.vendor.status', 'draft')
            ->assertJsonPath('data.vendor.has_active_subscription', false);
    }

    public function test_has_active_subscription_is_true_once_subscribed(): void
    {
        $user = $this->vendorUser();
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => '9812345678',
            'status' => 'active',
        ]);

        $plan = Plan::factory()->create();

        \App\Models\Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addYear(),
            'price_paise' => 99_900,
            'duration_days' => 365,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/vendors/me')
            ->assertOk()
            ->assertJsonPath('data.vendor.has_active_subscription', true);
    }

    public function test_has_active_subscription_is_false_once_expired(): void
    {
        $user = $this->vendorUser();
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => '9812345678',
            'status' => 'expired',
        ]);

        $plan = Plan::factory()->create();

        \App\Models\Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => 'expired',
            'start_date' => now()->subDays(400),
            'end_date' => now()->subDays(30),
            'price_paise' => 99_900,
            'duration_days' => 365,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/vendors/me')
            ->assertOk()
            ->assertJsonPath('data.vendor.has_active_subscription', false);
    }

    public function test_has_active_subscription_is_true_within_the_grace_window(): void
    {
        // task 7.1: a vendor who just entered grace must still land on
        // their dashboard, not get bounced back to plan selection.
        $user = $this->vendorUser();
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => '9812345678',
            'status' => 'grace',
        ]);

        $plan = Plan::factory()->create();

        \App\Models\Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => 'grace',
            'start_date' => now()->subDays(370),
            'end_date' => now()->subDays(3),
            'price_paise' => 99_900,
            'duration_days' => 365,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/vendors/me')
            ->assertOk()
            ->assertJsonPath('data.vendor.has_active_subscription', true);
    }

    public function test_has_active_subscription_is_false_past_the_grace_window(): void
    {
        $user = $this->vendorUser();
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => '9812345678',
            'status' => 'grace',
        ]);

        $plan = Plan::factory()->create();

        \App\Models\Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => 'grace',
            'start_date' => now()->subDays(400),
            'end_date' => now()->subDays(10),
            'price_paise' => 99_900,
            'duration_days' => 365,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/vendors/me')
            ->assertOk()
            ->assertJsonPath('data.vendor.has_active_subscription', false);
    }

    public function test_only_returns_the_callers_own_vendor_not_someone_elses(): void
    {
        $other = $this->vendorUser();
        Vendor::create([
            'user_id' => $other->id,
            'business_name' => 'Someone Elses Shop',
            'owner_name' => 'Someone Else',
            'phone' => '9800000001',
            'status' => 'draft',
        ]);

        $me = $this->vendorUser();
        Vendor::create([
            'user_id' => $me->id,
            'business_name' => 'My Shop',
            'owner_name' => 'Me',
            'phone' => '9800000002',
            'status' => 'draft',
        ]);

        $this->actingAs($me, 'sanctum')
            ->getJson('/api/vendors/me')
            ->assertOk()
            ->assertJsonPath('data.vendor.business_name', 'My Shop');
    }

    // ── active_subscription (dashboard data, task 4.2) ──────────────────

    public function test_active_subscription_is_null_with_no_subscription(): void
    {
        $user = $this->vendorUser();
        Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => '9812345678',
            'status' => 'draft',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/vendors/me')
            ->assertOk()
            ->assertJsonPath('data.active_subscription', null);
    }

    public function test_active_subscription_carries_plan_name_days_remaining_and_quota(): void
    {
        $user = $this->vendorUser();
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => '9812345678',
            'status' => 'active',
        ]);

        $plan = Plan::factory()->create(['name' => 'Gold']);
        PlanQuota::where('plan_id', $plan->id)->update([
            'max_categories' => 5,
            'max_subcategories' => 15,
            'max_zones' => 5,
        ]);

        $subscription = Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addDays(10)->startOfDay(),
            'price_paise' => 99_900,
            'duration_days' => 365,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        SubscriptionItem::insert([
            ['subscription_id' => $subscription->id, 'item_type' => 'category', 'item_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['subscription_id' => $subscription->id, 'item_type' => 'category', 'item_id' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['subscription_id' => $subscription->id, 'item_type' => 'subcategory', 'item_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['subscription_id' => $subscription->id, 'item_type' => 'zone', 'item_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/vendors/me')
            ->assertOk()
            ->assertJsonPath('data.active_subscription.plan_name', 'Gold')
            ->assertJsonPath('data.active_subscription.days_remaining', 10)
            ->assertJsonPath('data.active_subscription.quota.categories.used', 2)
            ->assertJsonPath('data.active_subscription.quota.categories.max', 5)
            ->assertJsonPath('data.active_subscription.quota.subcategories.used', 1)
            ->assertJsonPath('data.active_subscription.quota.subcategories.max', 15)
            ->assertJsonPath('data.active_subscription.quota.zones.used', 1)
            ->assertJsonPath('data.active_subscription.quota.zones.max', 5);
    }

    public function test_active_subscription_carries_the_selected_item_names(): void
    {
        // task 4.4's Services tab needs actual names, not just counts.
        $user = $this->vendorUser();
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => '9812345678',
            'status' => 'active',
        ]);

        $plan = Plan::factory()->create();
        $category = \App\Models\Category::factory()->create(['name' => 'AC Repair']);
        $subcategory = \App\Models\Subcategory::factory()->for($category)->create(['name' => 'Gas Filling']);
        $zone = \App\Models\Zone::factory()->active()->create(['name' => 'Gota']);

        $subscription = Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addDays(10),
            'price_paise' => 99_900,
            'duration_days' => 365,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        SubscriptionItem::insert([
            ['subscription_id' => $subscription->id, 'item_type' => 'category', 'item_id' => $category->id, 'created_at' => now(), 'updated_at' => now()],
            ['subscription_id' => $subscription->id, 'item_type' => 'subcategory', 'item_id' => $subcategory->id, 'created_at' => now(), 'updated_at' => now()],
            ['subscription_id' => $subscription->id, 'item_type' => 'zone', 'item_id' => $zone->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/vendors/me')
            ->assertOk()
            ->assertJsonPath('data.active_subscription.items.categories.0.id', $category->id)
            ->assertJsonPath('data.active_subscription.items.categories.0.name', 'AC Repair')
            ->assertJsonPath('data.active_subscription.items.subcategories.0.name', 'Gas Filling')
            // The parent id travels with each subcategory: the vendor app's
            // Services screen groups them under their category heading, and
            // without this it would have to pull the whole category tree
            // just to label the groups.
            ->assertJsonPath('data.active_subscription.items.subcategories.0.category_id', $category->id)
            ->assertJsonPath('data.active_subscription.items.zones.0.name', 'Gota');
    }

    public function test_active_subscription_items_still_show_a_since_deactivated_selection(): void
    {
        // Deactivating a category doesn't retroactively drop a vendor's
        // existing selection — same reasoning CategoryResource documents
        // for its own no-delete stance.
        $user = $this->vendorUser();
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => '9812345678',
            'status' => 'active',
        ]);

        $plan = Plan::factory()->create();
        $category = \App\Models\Category::factory()->create(['name' => 'AC Repair', 'is_active' => false]);

        $subscription = Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addDays(10),
            'price_paise' => 99_900,
            'duration_days' => 365,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        SubscriptionItem::insert([
            ['subscription_id' => $subscription->id, 'item_type' => 'category', 'item_id' => $category->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/vendors/me')
            ->assertOk()
            ->assertJsonPath('data.active_subscription.items.categories.0.name', 'AC Repair');
    }

    public function test_active_subscription_carries_photo_and_video_quota(): void
    {
        // task 4.5: portfolio upload now exists, so the omission from
        // earlier tasks no longer applies — used/max is real data.
        $user = $this->vendorUser();
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => '9812345678',
            'status' => 'active',
        ]);

        $plan = Plan::factory()->create();
        PlanQuota::where('plan_id', $plan->id)->update(['max_photos' => 10, 'max_videos' => 3]);

        $subscription = Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addDays(10),
            'price_paise' => 99_900,
            'duration_days' => 365,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $vendor->media()->create([
            'disk' => 'public', 'path' => 'a.jpg', 'type' => 'image', 'moderation_status' => 'pending',
        ]);
        $vendor->media()->create([
            'disk' => 'public', 'path' => 'b.jpg', 'type' => 'image', 'moderation_status' => 'approved',
        ]);
        $vendor->media()->create([
            'disk' => 'public', 'path' => 'c.jpg', 'type' => 'image', 'moderation_status' => 'rejected',
        ]);
        $vendor->media()->create([
            'disk' => 'public', 'path' => 'd.mp4', 'type' => 'video', 'moderation_status' => 'pending',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/vendors/me')
            ->assertOk()
            // 2 non-rejected photos, the rejected one excluded.
            ->assertJsonPath('data.active_subscription.quota.photos.used', 2)
            ->assertJsonPath('data.active_subscription.quota.photos.max', 10)
            ->assertJsonPath('data.active_subscription.quota.videos.used', 1)
            ->assertJsonPath('data.active_subscription.quota.videos.max', 3);
    }
}
