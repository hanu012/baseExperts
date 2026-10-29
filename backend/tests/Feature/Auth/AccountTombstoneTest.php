<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * The Phase 5.6 mechanism, built early alongside user management.
 *
 * users.email carries a unique index that soft-deleted rows keep occupying,
 * so a plain delete() would permanently bar that person from signing up
 * again — which SPEC section 4.10 requires be possible.
 */
class AccountTombstoneTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email = 'asha@example.com'): User
    {
        return User::factory()->role(UserRole::Customer)->create([
            'email' => $email,
            'password' => Hash::make('correct-horse-battery'),
        ]);
    }

    public function test_deleting_rewrites_the_email_to_a_tombstone(): void
    {
        $user = $this->user();

        $user->deleteWithTombstone();
        $user->refresh();

        $this->assertTrue($user->trashed());
        $this->assertTrue($user->isTombstoned());
        $this->assertStringStartsWith("deleted-{$user->id}-", $user->email);
        $this->assertStringEndsWith('@deleted.local', $user->email);
    }

    public function test_the_original_email_is_kept_for_restore(): void
    {
        $user = $this->user();

        $user->deleteWithTombstone();

        $this->assertSame('asha@example.com', $user->fresh()->original_email);
    }

    public function test_the_released_email_can_be_registered_again(): void
    {
        // The point of the whole mechanism.
        $original = $this->user();
        $original->deleteWithTombstone();

        $this->postJson('/api/auth/register', [
            'name' => 'Asha Again',
            'email' => 'asha@example.com',
            'role' => 'customer',
        ])->assertCreated();

        // Two rows now: the tombstoned original and the new live account,
        // both legal because only one of them holds the address.
        $this->assertSame(2, User::withTrashed()->count());

        $live = User::where('email', 'asha@example.com')->sole();
        $this->assertSame('Asha Again', $live->name);
        $this->assertNotSame($original->id, $live->id);

        $this->assertTrue(User::withTrashed()->find($original->id)->isTombstoned());
    }

    public function test_deleting_revokes_every_token(): void
    {
        // SoftDeletes hides the user from queries, but an already-issued token
        // does not care — it would keep authenticating a deleted account.
        $user = $this->user();
        $token = $user->createToken('pixel-8')->plainTextToken;

        $this->assertSame(1, $user->tokens()->count());

        $user->deleteWithTombstone();

        $this->assertSame(0, $user->tokens()->count());

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    public function test_a_deleted_user_cannot_log_in(): void
    {
        $this->user()->deleteWithTombstone();

        $this->postJson('/api/auth/login', [
            'email' => 'asha@example.com',
            'password' => 'correct-horse-battery',
            'device_name' => 'pixel-8',
        ])->assertStatus(401);
    }

    public function test_restoring_puts_the_original_email_back(): void
    {
        $user = $this->user();
        $user->deleteWithTombstone();

        $user->fresh()->restoreWithOriginalEmail();
        $user->refresh();

        $this->assertFalse($user->trashed());
        $this->assertSame('asha@example.com', $user->email);
        $this->assertNull($user->original_email);
        $this->assertFalse($user->isTombstoned());
    }

    public function test_a_restored_user_can_log_in_again(): void
    {
        $user = $this->user();
        $user->deleteWithTombstone();
        $user->fresh()->restoreWithOriginalEmail();

        $this->postJson('/api/auth/login', [
            'email' => 'asha@example.com',
            'password' => 'correct-horse-battery',
            'device_name' => 'pixel-8',
        ])->assertOk();
    }

    /**
     * The collision the tombstone creates by design: releasing the address
     * means someone else can take it before the original owner is restored.
     */
    public function test_restore_refuses_when_the_address_was_taken_meanwhile(): void
    {
        $user = $this->user();
        $user->deleteWithTombstone();

        // Someone else registers the freed address.
        User::factory()->role(UserRole::Customer)->create(['email' => 'asha@example.com']);

        $this->expectException(RuntimeException::class);

        try {
            $user->fresh()->restoreWithOriginalEmail();
        } finally {
            // Refusing must not half-apply: still deleted, still tombstoned.
            $reloaded = User::withTrashed()->find($user->id);
            $this->assertTrue($reloaded->trashed());
            $this->assertTrue($reloaded->isTombstoned());
            $this->assertSame('asha@example.com', $reloaded->original_email);
        }
    }

    public function test_the_refusal_message_names_the_address(): void
    {
        $user = $this->user();
        $user->deleteWithTombstone();
        User::factory()->role(UserRole::Customer)->create(['email' => 'asha@example.com']);

        try {
            $user->fresh()->restoreWithOriginalEmail();
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('asha@example.com', $e->getMessage());
            $this->assertStringContainsString('registered by another user', $e->getMessage());
        }
    }

    public function test_a_second_delete_cycle_does_not_collide_with_its_own_tombstone(): void
    {
        $user = $this->user();

        $user->deleteWithTombstone();
        $first = $user->fresh()->email;

        $user->fresh()->restoreWithOriginalEmail();

        // A second delete a second later must produce a different tombstone.
        $this->travel(2)->seconds();
        $user->fresh()->deleteWithTombstone();
        $second = $user->fresh()->email;

        $this->assertNotSame($first, $second);
        $this->assertSame('asha@example.com', $user->fresh()->original_email);
    }

    public function test_deleting_an_already_tombstoned_row_keeps_the_first_original(): void
    {
        // Guards against the original being overwritten with a tombstone,
        // which would lose the real address forever.
        $user = $this->user();
        $user->deleteWithTombstone();

        $tombstoned = $user->fresh();
        $tombstoned->deleteWithTombstone();

        $this->assertSame('asha@example.com', $tombstoned->fresh()->original_email);
    }

    public function test_tombstoned_addresses_cannot_receive_mail(): void
    {
        // .local is reserved and unroutable, so a stray notification to a
        // deleted account cannot reach a real inbox.
        $user = $this->user();
        $user->deleteWithTombstone();

        $this->assertStringEndsWith('@deleted.local', $user->fresh()->email);
    }
}
