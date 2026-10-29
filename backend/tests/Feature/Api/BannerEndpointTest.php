<?php

namespace Tests\Feature\Api;

use App\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/banners + POST /api/banners/{banner}/click (SPEC section 5
 * item 5) — the minimal public API that makes click_count real.
 */
class BannerEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function banner(array $overrides = []): Banner
    {
        return Banner::create(array_merge([
            'target_app' => 'customer',
            'title' => 'Diwali Sale',
            'position' => 'home_top',
            'image_path' => 'banners/diwali.jpg',
            'is_active' => true,
        ], $overrides));
    }

    // ── Serving query ────────────────────────────────────────────────────

    public function test_returns_only_active_banners_within_the_date_window_for_the_app(): void
    {
        $liveNow = $this->banner();
        $wrongApp = $this->banner(['target_app' => 'vendor']);
        $notYetStarted = $this->banner(['starts_at' => now()->addDay()]);
        $alreadyEnded = $this->banner(['ends_at' => now()->subDay()]);
        $inactive = $this->banner(['is_active' => false]);

        $response = $this->getJson('/api/banners?target_app=customer')->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$liveNow->id], $ids);
        $this->assertNotContains($wrongApp->id, $ids);
        $this->assertNotContains($notYetStarted->id, $ids);
        $this->assertNotContains($alreadyEnded->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_a_banner_with_no_date_window_is_always_within_it(): void
    {
        $banner = $this->banner(['starts_at' => null, 'ends_at' => null]);

        $response = $this->getJson('/api/banners?target_app=customer')->assertOk();

        $this->assertSame([$banner->id], array_column($response->json('data'), 'id'));
    }

    public function test_the_position_filter_narrows_when_given_and_is_optional(): void
    {
        $homeTop = $this->banner(['position' => 'home_top']);
        $homeBottom = $this->banner(['position' => 'home_bottom']);

        $filtered = $this->getJson('/api/banners?target_app=customer&position=home_top')->assertOk();
        $this->assertSame([$homeTop->id], array_column($filtered->json('data'), 'id'));

        $unfiltered = $this->getJson('/api/banners?target_app=customer')->assertOk();
        $ids = array_column($unfiltered->json('data'), 'id');
        $this->assertContains($homeTop->id, $ids);
        $this->assertContains($homeBottom->id, $ids);
    }

    public function test_the_response_is_unpaginated(): void
    {
        $this->banner();

        $response = $this->getJson('/api/banners?target_app=customer')->assertOk();

        $this->assertArrayNotHasKey('meta', $response->json());
        $this->assertIsArray($response->json('data'));
    }

    public function test_target_app_is_required_and_validated(): void
    {
        $this->getJson('/api/banners')->assertStatus(422);
        $this->getJson('/api/banners?target_app=not-a-real-app')->assertStatus(422);
    }

    public function test_click_count_is_not_exposed_in_the_serving_response(): void
    {
        $this->banner();

        $response = $this->getJson('/api/banners?target_app=customer')->assertOk();

        $this->assertArrayNotHasKey('click_count', $response->json('data.0'));
    }

    // ── Click tracking ───────────────────────────────────────────────────

    public function test_a_click_increments_the_count_by_exactly_one(): void
    {
        $banner = $this->banner();

        $this->postJson("/api/banners/{$banner->id}/click")->assertOk();

        $this->assertSame(1, $banner->fresh()->click_count);
    }

    public function test_concurrent_style_repeated_clicks_are_not_lost(): void
    {
        $banner = $this->banner();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/banners/{$banner->id}/click")->assertOk();
        }

        $this->assertSame(5, $banner->fresh()->click_count);
    }

    public function test_clicking_a_nonexistent_banner_404s(): void
    {
        $this->postJson('/api/banners/999999/click')->assertNotFound();
    }
}
