<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\PasswordResetRequest;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * POST /api/auth/password-reset-requests — "Forgot password?" in the apps.
 * Emails a reset link (Laravel by default); nothing reaches an admin.
 */
class PasswordResetRequestEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_known_email_is_sent_a_reset_link(): void
    {
        Notification::fake();
        $user = User::factory()->role(UserRole::Vendor)->create(['email' => 'asha@example.com']);

        $this->postJson('/api/auth/password-reset-requests', ['email' => 'asha@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.method', 'laravel');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    /** The user is told they typed an address with no account. */
    public function test_an_unknown_email_is_refused_and_nothing_is_sent(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'known@example.com']);

        $this->postJson('/api/auth/password-reset-requests', ['email' => 'nobody@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.fields.email.0', 'No account found with this email. Check the address and try again.');

        Notification::assertNothingSent();
    }

    public function test_surrounding_spaces_do_not_hide_an_account(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'known@example.com']);

        $this->postJson('/api/auth/password-reset-requests', ['email' => '  known@example.com '])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_a_deleted_account_is_treated_as_unknown(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'gone@example.com'])->delete();

        $this->postJson('/api/auth/password-reset-requests', ['email' => 'gone@example.com'])
            ->assertStatus(422);

        Notification::assertNothingSent();
    }

    /** Resets are self-service only: nothing is queued for an admin. */
    public function test_no_admin_request_is_filed(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'self@example.com']);

        $this->postJson('/api/auth/password-reset-requests', ['email' => 'self@example.com'])->assertOk();

        $this->assertSame(0, PasswordResetRequest::query()->count());
    }

    public function test_an_invalid_email_is_rejected(): void
    {
        $this->postJson('/api/auth/password-reset-requests', ['email' => 'not-an-email'])
            ->assertStatus(422);
    }

    public function test_it_changes_no_password(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'safe@example.com']);
        $hash = $user->password;

        $this->postJson('/api/auth/password-reset-requests', ['email' => 'safe@example.com'])->assertOk();

        $this->assertSame($hash, $user->fresh()->password);
        $this->assertFalse((bool) $user->fresh()->must_change_password);
    }

    /**
     * Refused guesses count too — the limit is what stops this endpoint
     * being used to test which emails have accounts.
     */
    public function test_it_is_rate_limited(): void
    {
        Notification::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/password-reset-requests', ['email' => "n{$i}@example.com"])->assertStatus(422);
        }

        $this->postJson('/api/auth/password-reset-requests', ['email' => 'n6@example.com'])
            ->assertStatus(429);
    }
}
