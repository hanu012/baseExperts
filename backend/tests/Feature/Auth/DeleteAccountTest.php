<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * DELETE /api/user (SPEC section 4 item 10, "required for app store
 * compliance") — the self-service entry point onto
 * User::deleteWithTombstone(), whose own mechanics are already covered
 * by AccountTombstoneTest. This file only covers the HTTP layer: the
 * password gate and the role scope.
 */
class DeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role, string $password = 'correct-horse-battery'): User
    {
        return User::factory()->role($role)->create([
            'password' => Hash::make($password),
            'must_change_password' => false,
        ]);
    }

    public function test_deleting_with_the_correct_password_tombstones_the_account(): void
    {
        $user = $this->user(UserRole::Customer);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/user', ['password' => 'correct-horse-battery'])
            ->assertOk();

        $reloaded = $user->fresh();

        $this->assertTrue($reloaded->trashed());
        $this->assertTrue($reloaded->isTombstoned());
    }

    public function test_deleting_revokes_all_tokens(): void
    {
        $user = $this->user(UserRole::Customer);
        $token = $user->createToken('pixel-8')->plainTextToken;

        $this->withToken($token)
            ->deleteJson('/api/user', ['password' => 'correct-horse-battery'])
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_the_wrong_password_is_rejected_and_changes_nothing(): void
    {
        $user = $this->user(UserRole::Customer);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/user', ['password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_PASSWORD');

        $this->assertFalse($user->fresh()->trashed());
    }

    public function test_password_is_required(): void
    {
        $user = $this->user(UserRole::Customer);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/user', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertFalse($user->fresh()->trashed());
    }

    public function test_a_vendor_can_delete_their_own_account(): void
    {
        $user = $this->user(UserRole::Vendor);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/user', ['password' => 'correct-horse-battery'])
            ->assertOk();

        $this->assertTrue($user->fresh()->trashed());
    }

    public function test_a_salesman_can_delete_their_own_account(): void
    {
        $user = $this->user(UserRole::Salesman);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/user', ['password' => 'correct-horse-battery'])
            ->assertOk();

        $this->assertTrue($user->fresh()->trashed());
    }

    /**
     * Deliberately excluded, not merely untested — admins get no
     * self-delete path via the API even though the tombstone mechanism
     * itself is role-agnostic on User.
     */
    public function test_an_admin_cannot_use_the_self_service_endpoint(): void
    {
        $user = $this->user(UserRole::Admin);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/user', ['password' => 'correct-horse-battery'])
            ->assertStatus(403);

        $this->assertFalse($user->fresh()->trashed());
    }
}
