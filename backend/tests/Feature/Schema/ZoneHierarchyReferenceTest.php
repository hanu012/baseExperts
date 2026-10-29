<?php

namespace Tests\Feature\Schema;

use App\Exceptions\RecordInUseException;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SPEC section 8: a parent zone's "in use" count must be transitive.
 *
 * Effective active status is computed at match time as
 * `own is_active AND (no parent OR parent.is_active)`, so switching off a
 * parent silently drops every descendant out of matching. A count that only
 * looked at the parent's own row would read "Not used" while deactivating it
 * broke coverage for every vendor beneath it.
 */
class ZoneHierarchyReferenceTest extends TestCase
{
    use RefreshDatabase;

    private ?int $subscriptionId = null;

    private function subscriptionId(): int
    {
        if ($this->subscriptionId !== null) {
            return $this->subscriptionId;
        }

        $userId = DB::table('users')->insertGetId([
            'name' => 'Vendor One',
            'email' => 'vendor-zone@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'vendor',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $vendorId = DB::table('vendors')->insertGetId([
            'user_id' => $userId,
            'business_name' => 'Cool Air', 'owner_name' => 'Vendor One',
            'phone' => '9900000042', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Standard', 'slug' => 'standard-zone',
            'price_paise' => 99900, 'duration_days' => 365,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->subscriptionId = DB::table('subscriptions')->insertGetId([
            'vendor_id' => $vendorId, 'plan_id' => $planId,
            'source' => 'self', 'status' => 'active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'price_paise' => 99900, 'duration_days' => 365,
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function reference(Zone $zone): void
    {
        DB::table('subscription_items')->insert([
            'subscription_id' => $this->subscriptionId(),
            'item_type' => 'zone',
            'item_id' => $zone->getKey(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_parents_count_includes_its_childrens_subscriptions(): void
    {
        $ahmedabad = Zone::factory()->create(['name' => 'Ahmedabad']);
        $gota = Zone::factory()->create(['parent_id' => $ahmedabad->id, 'name' => 'Gota']);

        $this->reference($gota);

        // The parent's own row is unreferenced, but deactivating it would drop
        // Gota out of matching — so it must not read as unused.
        $this->assertSame(1, $ahmedabad->countSubscriptionReferences());
        $this->assertSame(1, $gota->countSubscriptionReferences());
    }

    /**
     * Builds three levels, which SPEC section 8 now caps at two and
     * ZoneResource rejects at creation. Kept deliberately: descendantIds()
     * recurses, and this proves it stays correct if the depth constraint is
     * ever relaxed — the constraint is enforced in the panel, not the schema,
     * so the shape is reachable from a seeder or a console command.
     */
    public function test_the_count_reaches_grandchildren(): void
    {
        $state = Zone::factory()->create(['name' => 'Gujarat']);
        $city = Zone::factory()->create(['parent_id' => $state->id, 'name' => 'Ahmedabad']);
        $area = Zone::factory()->create(['parent_id' => $city->id, 'name' => 'Gota']);

        $this->reference($area);

        $this->assertSame(1, $state->countSubscriptionReferences());
        $this->assertSame(1, $city->countSubscriptionReferences());
    }

    public function test_an_unrelated_zone_is_not_counted(): void
    {
        $ahmedabad = Zone::factory()->create();
        $surat = Zone::factory()->create();

        $this->reference($surat);

        $this->assertSame(0, $ahmedabad->countSubscriptionReferences());
    }

    public function test_a_parent_cannot_be_deleted_while_a_child_is_subscribed(): void
    {
        // Otherwise deleting the parent is a way around the child's guard.
        $ahmedabad = Zone::factory()->create();
        $gota = Zone::factory()->create(['parent_id' => $ahmedabad->id]);

        $this->reference($gota);

        $this->assertFalse($ahmedabad->isDeletable());

        $this->expectException(RecordInUseException::class);

        try {
            $ahmedabad->delete();
        } finally {
            $this->assertDatabaseHas('zones', ['id' => $ahmedabad->id]);
            $this->assertDatabaseHas('zones', ['id' => $gota->id]);
        }
    }

    public function test_an_unreferenced_subtree_can_still_be_deleted(): void
    {
        $ahmedabad = Zone::factory()->create();
        Zone::factory()->create(['parent_id' => $ahmedabad->id]);

        $this->assertTrue($ahmedabad->isDeletable());
    }

    public function test_descendant_ids_collects_the_whole_subtree(): void
    {
        $root = Zone::factory()->create();
        $a = Zone::factory()->create(['parent_id' => $root->id]);
        $b = Zone::factory()->create(['parent_id' => $root->id]);
        $deep = Zone::factory()->create(['parent_id' => $a->id]);
        $unrelated = Zone::factory()->create();

        $ids = $root->descendantIds();

        sort($ids);
        $expected = [$a->id, $b->id, $deep->id];
        sort($expected);

        $this->assertSame($expected, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    public function test_is_leaf_distinguishes_parents_from_leaves(): void
    {
        // SPEC section 8: only leaf zones match or count against quota.
        $parent = Zone::factory()->create();
        $leaf = Zone::factory()->create(['parent_id' => $parent->id]);

        $this->assertFalse($parent->fresh()->isLeaf());
        $this->assertTrue($leaf->isLeaf());
    }
}
