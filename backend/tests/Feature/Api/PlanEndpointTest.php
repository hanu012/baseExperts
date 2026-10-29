<?php

namespace Tests\Feature\Api;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_is_public_and_needs_no_token(): void
    {
        Plan::factory()->create();

        $this->getJson('/api/plans')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('error', null);
    }

    public function test_it_returns_the_plan_with_its_quota_flattened(): void
    {
        Plan::factory()->create(['name' => 'Gold']);

        $response = $this->getJson('/api/plans')->assertOk();

        $response->assertJsonPath('data.0.name', 'Gold')
            ->assertJsonStructure([
                'data' => [[
                    'id', 'name', 'slug', 'description',
                    'price_paise', 'price_rupees', 'duration_days', 'sort_order',
                    'max_categories', 'max_subcategories', 'max_zones',
                    'max_photos', 'max_videos', 'priority_rank',
                ]],
            ]);
    }

    public function test_price_rupees_matches_the_stored_paise(): void
    {
        Plan::factory()->priceInRupees('999.00')->create();

        $response = $this->getJson('/api/plans')->assertOk();

        $this->assertSame(99900, $response->json('data.0.price_paise'));
        $this->assertSame('999.00', $response->json('data.0.price_rupees'));
    }

    public function test_inactive_plans_are_excluded(): void
    {
        Plan::factory()->create(['name' => 'Visible']);
        Plan::factory()->inactive()->create(['name' => 'Hidden']);

        $response = $this->getJson('/api/plans')->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertContains('Visible', $names);
        $this->assertNotContains('Hidden', $names);
    }

    public function test_ordering_follows_sort_order(): void
    {
        Plan::factory()->sortedAt(30)->create(['name' => 'Third']);
        Plan::factory()->sortedAt(10)->create(['name' => 'First']);
        Plan::factory()->sortedAt(20)->create(['name' => 'Second']);

        $response = $this->getJson('/api/plans')->assertOk();

        $this->assertSame(
            ['First', 'Second', 'Third'],
            array_column($response->json('data'), 'name')
        );
    }

    public function test_the_response_is_not_paginated(): void
    {
        // Same deliberate exception as /api/categories: the app needs the
        // whole set to render plan-selection cards in one request.
        Plan::factory()->count(5)->create();

        $response = $this->getJson('/api/plans')->assertOk();

        $response->assertJsonMissingPath('meta');
        $this->assertCount(5, $response->json('data'));
    }
}
