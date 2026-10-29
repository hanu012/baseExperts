<?php

namespace Tests\Feature\Api;

use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZoneEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tree_is_public_and_needs_no_token(): void
    {
        Zone::factory()->active()->create();

        $this->getJson('/api/zones')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('error', null);
    }

    public function test_a_subdivided_zone_is_not_a_leaf_and_lists_its_children(): void
    {
        $ahmedabad = Zone::factory()->active()->create(['name' => 'Ahmedabad']);
        Zone::factory()->active()->create(['parent_id' => $ahmedabad->id, 'name' => 'Gota']);
        Zone::factory()->active()->create(['parent_id' => $ahmedabad->id, 'name' => 'Sola']);

        $response = $this->getJson('/api/zones')->assertOk();

        $response->assertJsonPath('data.0.name', 'Ahmedabad')
            ->assertJsonPath('data.0.is_leaf', false)
            ->assertJsonCount(2, 'data.0.children')
            ->assertJsonStructure([
                'data' => [
                    ['id', 'name', 'pincode', 'is_leaf',
                        'children' => [['id', 'name', 'pincode', 'is_leaf']]],
                ],
            ]);
    }

    public function test_a_standalone_zone_with_no_sub_zones_is_a_leaf(): void
    {
        // SPEC section 8: a top-level zone with no children is itself a leaf
        // and matchable/selectable — a newly added city not yet subdivided.
        Zone::factory()->active()->create(['name' => 'Rajkot']);

        $response = $this->getJson('/api/zones')->assertOk();

        $response->assertJsonPath('data.0.name', 'Rajkot')
            ->assertJsonPath('data.0.is_leaf', true)
            ->assertJsonCount(0, 'data.0.children');
    }

    public function test_a_leaf_child_is_a_leaf_too(): void
    {
        $ahmedabad = Zone::factory()->active()->create();
        Zone::factory()->active()->create(['parent_id' => $ahmedabad->id, 'name' => 'Gota']);

        $response = $this->getJson('/api/zones')->assertOk();

        $this->assertTrue($response->json('data.0.children.0.is_leaf'));
    }

    public function test_an_inactive_child_is_excluded_from_an_active_parent(): void
    {
        $ahmedabad = Zone::factory()->active()->create();
        Zone::factory()->active()->create(['parent_id' => $ahmedabad->id, 'name' => 'Live']);
        Zone::factory()->create(['parent_id' => $ahmedabad->id, 'name' => 'Draft']); // defaults inactive

        $response = $this->getJson('/api/zones')->assertOk();

        $names = array_column($response->json('data.0.children'), 'name');
        $this->assertSame(['Live'], $names);
    }

    public function test_a_city_whose_sub_zones_are_all_drafts_is_not_offered_as_a_leaf(): void
    {
        // Showing it with is_leaf: true and no children made the apps offer
        // it, and the subscribe validator then refused it as not a leaf.
        $surat = Zone::factory()->active()->create(['name' => 'Surat']);
        Zone::factory()->create(['parent_id' => $surat->id, 'name' => 'Adajan']); // defaults inactive
        Zone::factory()->active()->create(['name' => 'Standalone']);

        $response = $this->getJson('/api/zones')->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertNotContains('Surat', $names);
        $this->assertContains('Standalone', $names);
    }

    public function test_an_active_child_of_an_inactive_parent_is_excluded_entirely(): void
    {
        // SPEC section 8's "effective active status": a child needs its own
        // is_active AND its parent's, even though the parent row is never
        // physically cascaded.
        $draftParent = Zone::factory()->create(['name' => 'Draft City']); // defaults inactive
        Zone::factory()->active()->create(['parent_id' => $draftParent->id, 'name' => 'Gota']);

        $response = $this->getJson('/api/zones')->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertNotContains('Draft City', $names);
        $this->assertNotContains('Gota', $names);
    }

    public function test_inactive_top_level_zones_are_excluded(): void
    {
        Zone::factory()->create(['name' => 'Draft']); // defaults inactive
        Zone::factory()->active()->create(['name' => 'Live']);

        $response = $this->getJson('/api/zones')->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertSame(['Live'], $names);
    }

    public function test_the_response_is_not_paginated(): void
    {
        Zone::factory()->active()->count(5)->create();

        $response = $this->getJson('/api/zones')->assertOk();

        $response->assertJsonMissingPath('meta');
        $this->assertCount(5, $response->json('data'));
    }
}
