<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST/DELETE /api/device-tokens (BUILD_PLAN 7.2).
 */
class DeviceTokenEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function vendorUser(): User
    {
        return User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
    }

    public function test_registering_a_new_token_creates_a_row(): void
    {
        $user = $this->vendorUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/device-tokens', ['token' => 'abc123', 'platform' => 'android'])
            ->assertOk();

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $user->id,
            'token' => 'abc123',
            'platform' => 'android',
        ]);
    }

    public function test_registering_the_same_token_again_upserts_not_duplicates(): void
    {
        $user = $this->vendorUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/device-tokens', ['token' => 'abc123', 'platform' => 'android'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/device-tokens', ['token' => 'abc123', 'platform' => 'android'])
            ->assertOk();

        $this->assertSame(1, DeviceToken::where('token', 'abc123')->count());
    }

    /**
     * FCM tokens identify a physical install, not a permanent owner —
     * a token re-registered under a different account (device
     * reassigned, different user logged in) must re-associate, not
     * error or silently keep pointing at the old owner.
     */
    public function test_registering_an_existing_token_under_a_different_user_reassigns_it(): void
    {
        $first = $this->vendorUser();
        $second = $this->vendorUser();

        $this->actingAs($first, 'sanctum')
            ->postJson('/api/device-tokens', ['token' => 'shared-token', 'platform' => 'ios']);

        $this->actingAs($second, 'sanctum')
            ->postJson('/api/device-tokens', ['token' => 'shared-token', 'platform' => 'ios'])
            ->assertOk();

        $this->assertSame(1, DeviceToken::where('token', 'shared-token')->count());
        $this->assertSame($second->id, DeviceToken::where('token', 'shared-token')->sole()->user_id);
    }

    public function test_platform_must_be_android_or_ios(): void
    {
        $user = $this->vendorUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/device-tokens', ['token' => 'abc123', 'platform' => 'windows'])
            ->assertStatus(422);
    }

    public function test_unregistering_removes_the_callers_own_token(): void
    {
        $user = $this->vendorUser();
        DeviceToken::create(['user_id' => $user->id, 'token' => 'abc123', 'platform' => 'android']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/device-tokens', ['token' => 'abc123'])
            ->assertOk();

        $this->assertDatabaseMissing('device_tokens', ['token' => 'abc123']);
    }

    public function test_unregistering_someone_elses_token_does_nothing(): void
    {
        $owner = $this->vendorUser();
        $other = $this->vendorUser();
        DeviceToken::create(['user_id' => $owner->id, 'token' => 'abc123', 'platform' => 'android']);

        $this->actingAs($other, 'sanctum')
            ->deleteJson('/api/device-tokens', ['token' => 'abc123'])
            ->assertOk();

        $this->assertDatabaseHas('device_tokens', ['token' => 'abc123', 'user_id' => $owner->id]);
    }

    public function test_a_guest_cannot_register_a_token(): void
    {
        $this->postJson('/api/device-tokens', ['token' => 'abc123', 'platform' => 'android'])
            ->assertStatus(401);
    }

    public function test_an_admin_role_is_rejected_by_the_role_gate(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/device-tokens', ['token' => 'abc123', 'platform' => 'android'])
            ->assertStatus(403);
    }
}
