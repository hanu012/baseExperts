<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'asha@example.com',
            'password' => Hash::make('correct-horse-battery'),
            'role' => UserRole::Vendor,
        ], $overrides));
    }

    public function test_a_user_can_log_in_and_receives_a_token_and_their_role(): void
    {
        $this->user();

        $this->postJson('/api/auth/login', [
            'email' => 'asha@example.com',
            'password' => 'correct-horse-battery',
            'device_name' => 'pixel-8',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('error', null)
            ->assertJsonPath('data.user.role', 'vendor')
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'role']]]);
    }

    public function test_the_returned_token_authenticates_subsequent_requests(): void
    {
        $this->user();

        $token = $this->postJson('/api/auth/login', [
            'email' => 'asha@example.com',
            'password' => 'correct-horse-battery',
            'device_name' => 'pixel-8',
        ])->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.email', 'asha@example.com');
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $this->user();

        $this->postJson('/api/auth/login', [
            'email' => 'asha@example.com',
            'password' => 'wrong',
            'device_name' => 'pixel-8',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_an_unknown_email_returns_the_same_error_as_a_wrong_password(): void
    {
        // Identical responses so the endpoint cannot be used to discover which
        // email addresses have accounts.
        $this->user();

        $unknown = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'correct-horse-battery',
            'device_name' => 'pixel-8',
        ]);

        $wrongPassword = $this->postJson('/api/auth/login', [
            'email' => 'asha@example.com',
            'password' => 'wrong',
            'device_name' => 'pixel-8',
        ]);

        $unknown->assertStatus(401);
        $wrongPassword->assertStatus(401);
        $this->assertSame($wrongPassword->json('error'), $unknown->json('error'));
    }

    public function test_a_soft_deleted_user_cannot_log_in(): void
    {
        $this->user()->delete();

        $this->postJson('/api/auth/login', [
            'email' => 'asha@example.com',
            'password' => 'correct-horse-battery',
            'device_name' => 'pixel-8',
        ])->assertStatus(401);
    }

    public function test_logging_in_again_on_the_same_device_replaces_that_devices_token(): void
    {
        $user = $this->user();

        $first = $this->postJson('/api/auth/login', [
            'email' => 'asha@example.com',
            'password' => 'correct-horse-battery',
            'device_name' => 'pixel-8',
        ])->json('data.token');

        $second = $this->postJson('/api/auth/login', [
            'email' => 'asha@example.com',
            'password' => 'correct-horse-battery',
            'device_name' => 'pixel-8',
        ])->json('data.token');

        // One token per device, and the superseded one stops working.
        $this->assertSame(1, $user->tokens()->where('name', 'pixel-8')->count());
        $this->assertNotSame($first, $second);

        $this->withHeader('Authorization', 'Bearer '.$first)
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    public function test_logging_in_on_a_second_device_keeps_the_first_device_signed_in(): void
    {
        $user = $this->user();

        $phone = $this->postJson('/api/auth/login', [
            'email' => 'asha@example.com',
            'password' => 'correct-horse-battery',
            'device_name' => 'pixel-8',
        ])->json('data.token');

        $this->postJson('/api/auth/login', [
            'email' => 'asha@example.com',
            'password' => 'correct-horse-battery',
            'device_name' => 'ipad-air',
        ])->assertOk();

        $this->assertSame(2, $user->tokens()->count());

        $this->withHeader('Authorization', 'Bearer '.$phone)
            ->getJson('/api/user')
            ->assertOk();
    }

    private function attemptLogin(string $password, string $email = 'asha@example.com'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => $password,
            'device_name' => 'pixel-8',
        ]);
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->attemptLogin('wrong')->assertStatus(401);
        }

        $this->attemptLogin('wrong')
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS');
    }

    public function test_the_lockout_also_blocks_the_correct_password(): void
    {
        // Otherwise the limiter would be trivially bypassable by an attacker
        // who guesses correctly on the sixth try.
        $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->attemptLogin('wrong')->assertStatus(401);
        }

        $this->attemptLogin('correct-horse-battery')->assertStatus(429);
    }

    public function test_successful_logins_never_consume_the_rate_limit_budget(): void
    {
        $this->user();

        // Well past the 5-attempt limit: none of these are failures, so none
        // of them should count.
        for ($i = 0; $i < 12; $i++) {
            $this->attemptLogin('correct-horse-battery')->assertOk();
        }
    }

    public function test_a_successful_login_clears_earlier_failures(): void
    {
        $this->user();

        foreach (range(1, 4) as $ignored) {
            $this->attemptLogin('wrong')->assertStatus(401);
        }

        $this->attemptLogin('correct-horse-battery')->assertOk();

        // Counter reset, so a fresh run of 5 failures is needed to lock out.
        foreach (range(1, 5) as $ignored) {
            $this->attemptLogin('wrong')->assertStatus(401);
        }

        $this->attemptLogin('wrong')->assertStatus(429);
    }

    public function test_failed_attempts_for_an_unknown_email_also_count(): void
    {
        // Stops an attacker probing addresses without ever tripping a limit.
        for ($i = 0; $i < 5; $i++) {
            $this->attemptLogin('wrong', 'nobody@example.com')->assertStatus(401);
        }

        $this->attemptLogin('wrong', 'nobody@example.com')->assertStatus(429);
    }

    public function test_the_throttled_response_reports_when_to_retry(): void
    {
        $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->attemptLogin('wrong');
        }

        $this->attemptLogin('wrong')
            ->assertStatus(429)
            ->assertJsonFragment(['code' => 'TOO_MANY_ATTEMPTS'])
            ->assertSee('seconds');
    }

    public function test_the_throttle_does_not_leak_across_accounts(): void
    {
        // Keyed on email + IP, so hammering one account must not lock out
        // another user coming from the same address.
        $this->user();
        $this->user(['email' => 'bhavin@example.com']);

        for ($i = 0; $i < 6; $i++) {
            $this->attemptLogin('wrong');
        }

        $this->attemptLogin('correct-horse-battery', 'bhavin@example.com')->assertOk();
    }
}
