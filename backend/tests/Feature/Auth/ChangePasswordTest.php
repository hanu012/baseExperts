<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Forced password change on first login (SPEC section 2.1).
 */
class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private function salesman(bool $mustChange = true): User
    {
        return User::factory()->role(UserRole::Salesman)->create([
            'email' => 'ravi@example.com',
            'password' => Hash::make('temp-password-123'),
            'must_change_password' => $mustChange,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('pixel-8')->plainTextToken;
    }

    // No withToken() helper here: Laravel's TestCase already declares a
    // public withToken(), which sets the Bearer header. Redeclaring it
    // private is a fatal error, not an override — the same base-class
    // collision CLAUDE.md warns about for Filament components.

    public function test_login_tells_the_app_a_change_is_required(): void
    {
        $this->salesman();

        $this->postJson('/api/auth/login', [
            'email' => 'ravi@example.com',
            'password' => 'temp-password-123',
            'device_name' => 'pixel-8',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.must_change_password', true);
    }

    public function test_a_normal_user_is_not_flagged(): void
    {
        $this->salesman(mustChange: false);

        $this->postJson('/api/auth/login', [
            'email' => 'ravi@example.com',
            'password' => 'temp-password-123',
            'device_name' => 'pixel-8',
        ])->assertJsonPath('data.user.must_change_password', false);
    }

    // Server-side enforcement.

    /**
     * The client redirect is advisory: the login response already carries a
     * working token. Without this the requirement holds only for users who go
     * through the UI.
     */
    public function test_other_endpoints_are_blocked_until_the_change(): void
    {
        $token = $this->tokenFor($this->salesman());

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_change_password_itself_stays_reachable(): void
    {
        // Otherwise the user is locked out of the only way forward.
        $token = $this->tokenFor($this->salesman());

        $this->withToken($token)
            ->postJson('/api/auth/change-password', [
                'current_password' => 'temp-password-123',
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])->assertOk();
    }

    public function test_logout_stays_reachable(): void
    {
        // Trapping someone in a session they cannot leave is worse than the
        // risk being managed.
        $token = $this->tokenFor($this->salesman());

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
    }

    public function test_unflagged_users_are_unaffected(): void
    {
        $token = $this->tokenFor($this->salesman(mustChange: false));

        $this->withToken($token)->getJson('/api/user')->assertOk();
    }

    // Changing.

    public function test_a_successful_change_clears_the_flag_and_unblocks_the_api(): void
    {
        $user = $this->salesman();
        $token = $this->tokenFor($user);

        $this->withToken($token)->postJson('/api/auth/change-password', [
            'current_password' => 'temp-password-123',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.must_change_password', false);

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertOk();
    }

    public function test_the_wrong_current_password_is_rejected(): void
    {
        // Without this, a stolen device with a live token is a full takeover.
        $token = $this->tokenFor($this->salesman());

        $this->withToken($token)->postJson('/api/auth/change-password', [
            'current_password' => 'not-the-temp-password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_CURRENT_PASSWORD');
    }

    public function test_reusing_the_same_password_is_rejected(): void
    {
        // Otherwise a no-op change clears the flag and defeats the rule.
        $user = $this->salesman();
        $token = $this->tokenFor($user);

        $this->withToken($token)->postJson('/api/auth/change-password', [
            'current_password' => 'temp-password-123',
            'password' => 'temp-password-123',
            'password_confirmation' => 'temp-password-123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PASSWORD_UNCHANGED');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_a_mismatched_confirmation_is_rejected(): void
    {
        $token = $this->tokenFor($this->salesman());

        $this->withToken($token)->postJson('/api/auth/change-password', [
            'current_password' => 'temp-password-123',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'something-else-entirely',
        ])->assertStatus(422);
    }

    public function test_other_devices_are_signed_out_but_this_one_survives(): void
    {
        $user = $this->salesman();
        $thisDevice = $this->tokenFor($user);
        $otherDevice = $user->createToken('ipad')->plainTextToken;

        $this->withToken($thisDevice)->postJson('/api/auth/change-password', [
            'current_password' => 'temp-password-123',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->assertSame(1, $user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($otherDevice)->getJson('/api/user')->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->withToken($thisDevice)->getJson('/api/user')->assertOk();
    }

    public function test_the_new_password_works_for_a_fresh_login(): void
    {
        $user = $this->salesman();

        $this->withToken($this->tokenFor($user))->postJson('/api/auth/change-password', [
            'current_password' => 'temp-password-123',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => 'ravi@example.com',
            'password' => 'a-brand-new-password',
            'device_name' => 'pixel-8',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.must_change_password', false);
    }

    public function test_an_unauthenticated_call_is_rejected(): void
    {
        $this->postJson('/api/auth/change-password', [
            'current_password' => 'temp-password-123',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertStatus(401);
    }
}
