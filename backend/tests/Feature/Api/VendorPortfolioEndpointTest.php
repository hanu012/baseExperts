<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Media;
use App\Models\Plan;
use App\Models\PlanQuota;
use App\Models\Subcategory;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST/GET /api/vendors/me/portfolio (SPEC section 3 item 5, task 4.5) —
 * vendor portfolio photo/video upload within remaining quota, routed
 * through moderation before going live.
 */
class VendorPortfolioEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An active vendor with an active subscription on a plan with room for
     * 2 photos / 1 video, subscribed to one subcategory.
     *
     * @return array{0: User, 1: Vendor, 2: Subcategory}
     */
    private function activeVendorWithSubscription(int $maxPhotos = 2, int $maxVideos = 1): array
    {
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ]);

        $plan = Plan::factory()->create();
        PlanQuota::where('plan_id', $plan->id)->update([
            'max_categories' => 3,
            'max_subcategories' => 6,
            'max_zones' => 3,
            'max_photos' => $maxPhotos,
            'max_videos' => $maxVideos,
        ]);

        $category = \App\Models\Category::factory()->create();
        $subcategory = Subcategory::factory()->for($category)->create();

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
        ]);

        return [$user, $vendor->fresh(), $subcategory];
    }

    // ── Happy path ───────────────────────────────────────────────────────

    public function test_a_vendor_can_upload_a_photo_within_remaining_quota(): void
    {
        Storage::fake('public');
        [$user, , $subcategory] = $this->activeVendorWithSubscription();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/portfolio', [
                'type' => 'image',
                'subcategory_id' => $subcategory->id,
                'file' => UploadedFile::fake()->image('work.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.media.type', 'image')
            // Auto-approved: live for customers straight away.
            ->assertJsonPath('data.media.moderation_status', 'approved')
            ->assertJsonPath('data.quota.photos.used', 1)
            ->assertJsonPath('data.quota.photos.max', 2);

        $media = Media::sole();
        $this->assertSame('approved', $media->moderation_status);
        $this->assertNotNull($media->moderated_at);
        $this->assertSame(Vendor::currentUploadDisk(), $media->disk);
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_a_vendor_can_upload_a_video_within_remaining_quota(): void
    {
        Storage::fake('public');
        [$user, , $subcategory] = $this->activeVendorWithSubscription();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/portfolio', [
                'type' => 'video',
                'subcategory_id' => $subcategory->id,
                'file' => UploadedFile::fake()->create('clip.mp4', 2000, 'video/mp4'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.media.type', 'video')
            ->assertJsonPath('data.quota.videos.used', 1)
            ->assertJsonPath('data.quota.videos.max', 1);
    }

    // ── Quota (remaining, not absolute) ─────────────────────────────────

    public function test_exceeding_remaining_photo_quota_is_rejected(): void
    {
        Storage::fake('public');
        [$user, $vendor, $subcategory] = $this->activeVendorWithSubscription(maxPhotos: 1);

        $vendor->media()->create([
            'subcategory_id' => $subcategory->id,
            'disk' => 'public',
            'path' => 'vendor-portfolio/existing.jpg',
            'type' => 'image',
            'moderation_status' => 'pending',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/portfolio', [
                'type' => 'image',
                'subcategory_id' => $subcategory->id,
                'file' => UploadedFile::fake()->image('work.jpg'),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['file']]]);
    }

    /**
     * A purchased photo add-on (task 4.7) expands what quotaSummary()
     * and the upload check both honor — not just the endpoint that
     * sold it.
     */
    public function test_a_purchased_addon_expands_the_remaining_photo_quota(): void
    {
        Storage::fake('public');
        [$user, $vendor, $subcategory] = $this->activeVendorWithSubscription(maxPhotos: 1);

        $vendor->media()->create([
            'subcategory_id' => $subcategory->id,
            'disk' => 'public',
            'path' => 'vendor-portfolio/existing.jpg',
            'type' => 'image',
            'moderation_status' => 'pending',
        ]);

        $subscription = $vendor->currentActiveSubscription();
        \App\Models\SubscriptionAddon::create([
            'subscription_id' => $subscription->id,
            'resource' => 'photos',
            'quantity' => 1,
            'price_paise' => 500,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        // Bare max is 1 (already used); the +1 add-on makes a second
        // upload fit.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/portfolio', [
                'type' => 'image',
                'subcategory_id' => $subcategory->id,
                'file' => UploadedFile::fake()->image('work.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.quota.photos.used', 2)
            ->assertJsonPath('data.quota.photos.max', 2);
    }

    public function test_a_rejected_upload_frees_its_quota_slot(): void
    {
        Storage::fake('public');
        [$user, $vendor, $subcategory] = $this->activeVendorWithSubscription(maxPhotos: 1);

        $vendor->media()->create([
            'subcategory_id' => $subcategory->id,
            'disk' => 'public',
            'path' => 'vendor-portfolio/existing.jpg',
            'type' => 'image',
            'moderation_status' => 'rejected',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/portfolio', [
                'type' => 'image',
                'subcategory_id' => $subcategory->id,
                'file' => UploadedFile::fake()->image('work.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.quota.photos.used', 1);
    }

    public function test_an_approved_upload_still_counts_toward_quota(): void
    {
        Storage::fake('public');
        [$user, $vendor, $subcategory] = $this->activeVendorWithSubscription(maxPhotos: 1);

        $vendor->media()->create([
            'subcategory_id' => $subcategory->id,
            'disk' => 'public',
            'path' => 'vendor-portfolio/existing.jpg',
            'type' => 'image',
            'moderation_status' => 'approved',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/portfolio', [
                'type' => 'image',
                'subcategory_id' => $subcategory->id,
                'file' => UploadedFile::fake()->image('work.jpg'),
            ])
            ->assertStatus(422);
    }

    // ── Server-side re-verification ──────────────────────────────────────

    public function test_a_subcategory_not_currently_offered_is_rejected(): void
    {
        Storage::fake('public');
        [$user] = $this->activeVendorWithSubscription();
        $otherSubcategory = Subcategory::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/portfolio', [
                'type' => 'image',
                'subcategory_id' => $otherSubcategory->id,
                'file' => UploadedFile::fake()->image('work.jpg'),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['subcategory_id']]]);
    }

    public function test_a_non_image_file_declared_as_image_is_rejected(): void
    {
        Storage::fake('public');
        [$user, , $subcategory] = $this->activeVendorWithSubscription();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/portfolio', [
                'type' => 'image',
                'subcategory_id' => $subcategory->id,
                'file' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['file']]]);
    }

    public function test_a_video_over_the_50mb_cap_is_rejected(): void
    {
        Storage::fake('public');
        [$user, , $subcategory] = $this->activeVendorWithSubscription();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/portfolio', [
                'type' => 'video',
                'subcategory_id' => $subcategory->id,
                'file' => UploadedFile::fake()->create('huge.mp4', 60000, 'video/mp4'),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['file']]]);
    }

    public function test_a_vendor_with_no_active_subscription_is_rejected(): void
    {
        Storage::fake('public');
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => '9812345678',
            'status' => 'draft',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vendors/me/portfolio', [
                'type' => 'image',
                'subcategory_id' => 1,
                'file' => UploadedFile::fake()->image('work.jpg'),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['subscription']]]);
    }

    // ── Role gating ──────────────────────────────────────────────────────

    public function test_a_salesman_cannot_call_this_endpoint(): void
    {
        Storage::fake('public');
        $salesmanUser = User::factory()->role(UserRole::Salesman)->create(['must_change_password' => false]);

        $this->actingAs($salesmanUser, 'sanctum')
            ->postJson('/api/vendors/me/portfolio', [
                'type' => 'image',
                'subcategory_id' => 1,
                'file' => UploadedFile::fake()->image('work.jpg'),
            ])
            ->assertStatus(403);
    }

    // ── GET /vendors/me/portfolio ────────────────────────────────────────

    public function test_the_index_lists_the_vendors_own_media_and_quota(): void
    {
        Storage::fake('public');
        [$user, $vendor, $subcategory] = $this->activeVendorWithSubscription();

        $vendor->media()->create([
            'subcategory_id' => $subcategory->id,
            'disk' => 'public',
            'path' => 'vendor-portfolio/photo.jpg',
            'type' => 'image',
            'moderation_status' => 'approved',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/vendors/me/portfolio')
            ->assertOk()
            ->assertJsonCount(1, 'data.media')
            ->assertJsonPath('data.media.0.moderation_status', 'approved')
            ->assertJsonPath('data.media.0.subcategory_name', $subcategory->name)
            ->assertJsonPath('data.quota.photos.used', 1)
            ->assertJsonPath('data.quota.photos.max', 2);
    }

    public function test_the_index_only_shows_the_callers_own_media(): void
    {
        Storage::fake('public');
        [, $vendor, $subcategory] = $this->activeVendorWithSubscription();
        $vendor->media()->create([
            'subcategory_id' => $subcategory->id,
            'disk' => 'public',
            'path' => 'vendor-portfolio/other.jpg',
            'type' => 'image',
            'moderation_status' => 'pending',
        ]);

        [$me] = $this->activeVendorWithSubscription();

        $this->actingAs($me, 'sanctum')
            ->getJson('/api/vendors/me/portfolio')
            ->assertOk()
            ->assertJsonCount(0, 'data.media');
    }
}
