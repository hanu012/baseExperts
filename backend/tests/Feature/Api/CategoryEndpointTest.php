<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tree_is_public_and_needs_no_token(): void
    {
        Category::factory()->create();

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('error', null);
    }

    public function test_it_returns_categories_with_nested_subcategories(): void
    {
        $category = Category::factory()->create(['name' => 'AC Repair']);
        Subcategory::factory()->for($category)->create(['name' => 'Gas Filling']);
        Subcategory::factory()->for($category)->create(['name' => 'Installation']);

        $response = $this->getJson('/api/categories')->assertOk();

        $response->assertJsonPath('data.0.name', 'AC Repair')
            ->assertJsonCount(2, 'data.0.subcategories')
            ->assertJsonStructure([
                'data' => [
                    ['id', 'name', 'slug', 'icon_url', 'sort_order',
                        'subcategories' => [['id', 'category_id', 'name', 'slug', 'icon_url', 'sort_order',
                            'service_type', 'is_popular']]],
                ],
            ]);
    }

    public function test_inactive_categories_are_excluded(): void
    {
        Category::factory()->create(['name' => 'Visible']);
        Category::factory()->inactive()->create(['name' => 'Hidden']);

        $response = $this->getJson('/api/categories')->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertContains('Visible', $names);
        $this->assertNotContains('Hidden', $names);
    }

    public function test_inactive_subcategories_are_excluded_from_an_active_parent(): void
    {
        $category = Category::factory()->create();
        Subcategory::factory()->for($category)->create(['name' => 'Live']);
        Subcategory::factory()->for($category)->inactive()->create(['name' => 'Retired']);

        $response = $this->getJson('/api/categories')->assertOk();

        $names = array_column($response->json('data.0.subcategories'), 'name');
        $this->assertSame(['Live'], $names);
    }

    public function test_ordering_follows_sort_order(): void
    {
        Category::factory()->sortedAt(30)->create(['name' => 'Third']);
        Category::factory()->sortedAt(10)->create(['name' => 'First']);
        Category::factory()->sortedAt(20)->create(['name' => 'Second']);

        $response = $this->getJson('/api/categories')->assertOk();

        $this->assertSame(
            ['First', 'Second', 'Third'],
            array_column($response->json('data'), 'name')
        );
    }

    public function test_subcategories_follow_their_own_sort_order(): void
    {
        $category = Category::factory()->create();
        Subcategory::factory()->for($category)->sortedAt(20)->create(['name' => 'Later']);
        Subcategory::factory()->for($category)->sortedAt(5)->create(['name' => 'Sooner']);

        $response = $this->getJson('/api/categories')->assertOk();

        $this->assertSame(
            ['Sooner', 'Later'],
            array_column($response->json('data.0.subcategories'), 'name')
        );
    }

    public function test_the_response_is_not_paginated(): void
    {
        // A deliberate exception to CLAUDE.md's paginate-every-list rule: the
        // apps cache the whole tree on launch. If this ever starts paginating,
        // the mobile browse grid silently truncates.
        Category::factory()->count(20)->create();

        $response = $this->getJson('/api/categories')->assertOk();

        $response->assertJsonMissingPath('meta');
        $this->assertCount(20, $response->json('data'));
    }

    public function test_an_empty_tree_returns_an_empty_array_not_null(): void
    {
        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('data', []);
    }
}
