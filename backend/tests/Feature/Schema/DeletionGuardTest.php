<?php

namespace Tests\Feature\Schema;

use App\Exceptions\RecordInUseException;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * subscription_items carries item_id without a foreign key, so the database
 * cannot refuse these deletions itself. These tests cover the model-level
 * substitute.
 */
class DeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $slug = 'ac-repair'): Category
    {
        return Category::create(['name' => 'AC Repair', 'slug' => $slug]);
    }

    private function subcategory(Category $category, string $slug = 'gas-filling'): Subcategory
    {
        return Subcategory::create([
            'category_id' => $category->id,
            'name' => 'Gas Filling',
            'slug' => $slug,
        ]);
    }

    private function zone(string $slug = 'gota'): Zone
    {
        return Zone::create([
            'name' => 'Gota',
            'slug' => $slug,
            'polygon' => DB::raw(
                "ST_GeomFromText('POLYGON((72.52 23.09,72.58 23.09,72.58 23.13,72.52 23.13,72.52 23.09))',4326)"
            ),
            'pincode' => '382481',
        ]);
    }

    private ?int $subscriptionId = null;

    /**
     * A real subscription row, built through its full chain.
     *
     * Inserted with the query builder rather than factories because Phase 1
     * has only modelled categories, subcategories and zones so far. It has to
     * be real: subscription_items.subscription_id carries a genuine foreign
     * key, so a placeholder id is rejected by the database.
     */
    private function subscriptionId(): int
    {
        if ($this->subscriptionId !== null) {
            return $this->subscriptionId;
        }

        $userId = DB::table('users')->insertGetId([
            'name' => 'Vendor One',
            'email' => 'vendor-one@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'vendor',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vendorId = DB::table('vendors')->insertGetId([
            'user_id' => $userId,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Vendor One',
            'phone' => '9900000001',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Standard',
            'slug' => 'standard',
            'price_paise' => 99900,
            'duration_days' => 365,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->subscriptionId = DB::table('subscriptions')->insertGetId([
            'vendor_id' => $vendorId,
            'plan_id' => $planId,
            'source' => 'self',
            'status' => 'active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'price_paise' => 99900,
            'duration_days' => 365,
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function reference(string $type, int $id): void
    {
        DB::table('subscription_items')->insert([
            'subscription_id' => $this->subscriptionId(),
            'item_type' => $type,
            'item_id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_an_unreferenced_category_can_be_deleted(): void
    {
        $category = $this->category();

        $this->assertTrue($category->isDeletable());
        $category->delete();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_a_referenced_category_cannot_be_deleted(): void
    {
        $category = $this->category();
        $this->reference('category', $category->id);

        $this->assertFalse($category->isDeletable());

        $this->expectException(RecordInUseException::class);

        try {
            $category->delete();
        } finally {
            // The row must survive the attempt.
            $this->assertDatabaseHas('categories', ['id' => $category->id]);
        }
    }

    public function test_a_referenced_subcategory_cannot_be_deleted(): void
    {
        $subcategory = $this->subcategory($this->category());
        $this->reference('subcategory', $subcategory->id);

        $this->expectException(RecordInUseException::class);

        try {
            $subcategory->delete();
        } finally {
            $this->assertDatabaseHas('subcategories', ['id' => $subcategory->id]);
        }
    }

    public function test_a_referenced_zone_cannot_be_deleted(): void
    {
        $zone = $this->zone();
        $this->reference('zone', $zone->id);

        $this->expectException(RecordInUseException::class);

        try {
            $zone->delete();
        } finally {
            $this->assertDatabaseHas('zones', ['id' => $zone->id]);
        }
    }

    public function test_deleting_a_category_cannot_bypass_its_subcategorys_guard(): void
    {
        // The category itself is unreferenced, but its child is. A database
        // cascade would delete the child without firing the child's events,
        // so the parent must refuse too.
        $category = $this->category();
        $subcategory = $this->subcategory($category);
        $this->reference('subcategory', $subcategory->id);

        $this->expectException(RecordInUseException::class);

        try {
            $category->delete();
        } finally {
            $this->assertDatabaseHas('categories', ['id' => $category->id]);
            $this->assertDatabaseHas('subcategories', ['id' => $subcategory->id]);
        }
    }

    public function test_the_guard_is_scoped_to_the_right_item_type(): void
    {
        // A zone with the same numeric id as a category must not block it.
        $category = $this->category();
        $this->reference('zone', $category->id);

        $this->assertTrue($category->isDeletable());
        $category->delete();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    /**
     * One subscription stores a row for the category and one per
     * subcategory under it. The admin's "In use" column showed those rows
     * as subscriptions — "9 subscriptions" for a category two vendors bought.
     */
    public function test_one_subscription_counts_once_however_many_of_its_rows_match(): void
    {
        $category = $this->category();
        $this->reference('category', $category->id);

        foreach (['gas-filling', 'installation', 'servicing'] as $slug) {
            $this->reference('subcategory', $this->subcategory($category, $slug)->id);
        }

        $this->assertSame(4, DB::table('subscription_items')->count());
        $this->assertSame(1, $category->countSubscriptionReferences());
    }

    public function test_the_error_names_the_record_and_the_count(): void
    {
        $zone = $this->zone();
        $this->reference('zone', $zone->id);

        try {
            $zone->delete();
            $this->fail('Expected RecordInUseException.');
        } catch (RecordInUseException $e) {
            $this->assertStringContainsString('Gota', $e->getMessage());
            $this->assertStringContainsString('referenced by 1 subscription.', $e->getMessage());
            $this->assertStringContainsString('Deactivate it instead', $e->getMessage());
        }
    }

    public function test_deactivating_is_always_allowed(): void
    {
        // The documented escape hatch must actually work while referenced.
        $zone = $this->zone();
        $this->reference('zone', $zone->id);

        $zone->update(['is_active' => false]);

        $this->assertDatabaseHas('zones', ['id' => $zone->id, 'is_active' => 0]);
    }
}
