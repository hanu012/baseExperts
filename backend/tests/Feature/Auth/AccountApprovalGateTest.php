<?php

namespace Tests\Feature\Auth;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Admin approval is the single gate on an account (SPEC section 3.1).
 *
 * The shape being pinned here is deliberate and easy to get wrong in the
 * obvious direction: an unapproved user DOES get a working token and DOES
 * sign in. Refusing the token would leave them at the login screen with no
 * way to be told why their new account does nothing. The restriction lives
 * on the routes instead, so these tests check both halves — that the token
 * works, and that it buys almost nothing.
 */
class AccountApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * There is no VendorFactory in this codebase — vendors are built with
     * Vendor::create() throughout the suite, so this matches.
     *
     * must_change_password is forced off: RequirePasswordChange runs before
     * RequireApprovedAccount, so a user carrying both flags would be
     * stopped by the wrong one and these assertions would pass or fail for
     * the wrong reason.
     */
    private function pendingVendor(): User
    {
        $user = User::factory()->pending()->create([
            'email' => 'asha@example.com',
            'password' => Hash::make('correct-horse-battery'),
            'role' => UserRole::Vendor,
            'must_change_password' => false,
        ]);

        Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ]);

        return $user;
    }

    public function test_self_registration_leaves_the_account_pending(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', [
            'name' => 'Asha',
            'email' => 'asha@example.com',
            'role' => 'customer',
        ])->assertCreated()
            ->assertJsonPath('data.user.approval_status', 'pending');

        $this->assertSame(
            ApprovalStatus::Pending,
            User::where('email', 'asha@example.com')->sole()->approval_status,
        );
    }

    /**
     * Sign-up issues no token any more (the password is set from an emailed
     * link), so the pending screen is reached at the first sign-in — and
     * that token is the whole reason the app can show it. If this ever
     * starts returning null, the app has nowhere to land a new user.
     */
    public function test_the_first_sign_in_after_registering_issues_a_working_token(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', [
            'name' => 'Asha',
            'email' => 'asha@example.com',
            'role' => 'customer',
        ])->assertCreated();

        User::where('email', 'asha@example.com')->sole()
            ->resetPasswordFromEmailLink('correct-horse-battery');

        $token = $this->postJson('/api/auth/login', [
            'email' => 'asha@example.com',
            'password' => 'correct-horse-battery',
            'device_name' => 'pixel-8',
        ])->assertOk()
            ->assertJsonPath('data.user.approval_status', 'pending')
            ->json('data.token');

        $this->assertNotNull($token);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'pending');
    }

    public function test_a_pending_user_can_still_log_in(): void
    {
        $this->pendingVendor();

        $this->postJson('/api/auth/login', [
            'email' => 'asha@example.com',
            'password' => 'correct-horse-battery',
            'device_name' => 'pixel-8',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.approval_status', 'pending');
    }

    public function test_a_pending_user_is_blocked_from_ordinary_endpoints(): void
    {
        $this->actingAs($this->pendingVendor(), 'sanctum');

        $this->getJson('/api/vendors/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_PENDING_APPROVAL');
    }

    /**
     * The three things an unapproved account must still be able to do, or
     * the pending screen cannot function: read its own status, register for
     * the push that tells it the decision landed, and leave.
     */
    public function test_a_pending_user_can_read_their_own_profile(): void
    {
        $this->actingAs($this->pendingVendor(), 'sanctum');

        $this->getJson('/api/user')->assertOk();
    }

    public function test_a_pending_user_can_register_a_device_token(): void
    {
        $this->actingAs($this->pendingVendor(), 'sanctum');

        $this->postJson('/api/device-tokens', [
            'token' => str_repeat('a', 64),
            'platform' => 'android',
        ])->assertSuccessful();
    }

    /**
     * Driven through a real login rather than actingAs(): logout revokes
     * the token behind the request, and actingAs() fakes the guard without
     * issuing one, so currentAccessToken() would be null and the test would
     * fail on its own setup instead of on the gate.
     */
    public function test_a_pending_user_can_log_out(): void
    {
        $this->pendingVendor();

        $token = $this->postJson('/api/auth/login', [
            'email' => 'asha@example.com',
            'password' => 'correct-horse-battery',
            'device_name' => 'pixel-8',
        ])->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk();
    }

    /**
     * The allowlist is matched on method as well as path. `api/user` alone
     * would have opened the preferences PATCH under the same prefix.
     */
    public function test_a_pending_user_cannot_change_preferences(): void
    {
        $this->actingAs($this->pendingVendor(), 'sanctum');

        $this->patchJson('/api/user/preferences', ['language' => 'hi'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_PENDING_APPROVAL');
    }

    public function test_a_rejected_user_gets_the_reason_rather_than_a_bare_403(): void
    {
        $user = User::factory()
            ->rejected('Business address could not be confirmed.')
            ->create([
                'role' => UserRole::Customer,
                'must_change_password' => false,
            ]);

        Customer::create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum');

        $this->patchJson('/api/user/preferences', ['language' => 'hi'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_REJECTED')
            ->assertJsonPath('error.message', 'Business address could not be confirmed.');
    }

    public function test_an_approved_user_passes_straight_through(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Vendor,
            'must_change_password' => false,
        ]);

        Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ]);

        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/vendors/me')->assertOk();
    }

    /**
     * Approving has to take effect on the token the user already holds —
     * they are sitting on the pending screen with a live session, and
     * forcing a re-login to pick up the decision is a confusing dead end.
     */
    public function test_approval_unblocks_an_existing_session(): void
    {
        $user = $this->pendingVendor();

        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/vendors/me')->assertForbidden();

        $user->recordApprovalDecision(
            ApprovalStatus::Approved,
            User::factory()->role(UserRole::Admin)->create(),
        );

        $this->actingAs($user->fresh(), 'sanctum');

        $this->getJson('/api/vendors/me')->assertOk();
    }
}
