<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\PasswordResetRequest;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * "Forgot password?" — always an emailed link, sent by Laravel (default) or
 * Firebase per Settings → Password reset — plus the web pages the links
 * open. Firebase is faked with Http::fake(); no network.
 */
class PasswordResetMethodsTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'BrandNew#Pass2026';

    private function useMethod(string $method): void
    {
        Setting::where('key', 'password_reset_method')->update(['value' => $method]);
    }

    private function user(): User
    {
        return User::factory()->role(UserRole::Customer)->create([
            'email' => 'ravi@example.com',
            'must_change_password' => true,
        ]);
    }

    private function forgot(string $email = 'ravi@example.com'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/password-reset-requests', ['email' => $email])->assertOk();
    }

    /** Firebase set up: a throwaway service-account key and a Web API key. */
    private function configureFirebase(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        config()->set('services.fcm.project_id', 'demo-project');
        config()->set('services.fcm.client_email', 'firebase-adminsdk@demo-project.iam.gserviceaccount.com');
        config()->set('services.fcm.private_key_base64', base64_encode($pem));
        config()->set('services.firebase.web_api_key', 'web-api-key');
    }

    // ── Default ──────────────────────────────────────────────────────────

    public function test_by_default_this_server_emails_the_link(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->forgot()->assertJsonPath('data.method', 'laravel');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_a_leftover_admin_setting_is_treated_as_laravel(): void
    {
        Notification::fake();
        $this->useMethod('admin');
        $user = $this->user();

        $this->forgot()->assertJsonPath('data.method', 'laravel');

        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertFalse(PasswordResetRequest::where('user_id', $user->id)->exists());
    }

    // ── Laravel email link ───────────────────────────────────────────────

    public function test_laravel_mode_emails_a_reset_link_and_queues_nothing(): void
    {
        Notification::fake();
        $this->useMethod('laravel');
        $user = $this->user();

        $this->forgot()->assertJsonPath('data.method', 'laravel');

        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertFalse(PasswordResetRequest::where('user_id', $user->id)->exists());
    }

    public function test_an_unknown_email_is_refused_in_either_mode(): void
    {
        Notification::fake();
        Http::fake();

        foreach (['laravel', 'firebase'] as $method) {
            $this->useMethod($method);
            $this->forgot('nobody@example.com')->assertStatus(422);
        }

        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_the_laravel_link_page_sets_the_password_and_signs_out_everywhere(): void
    {
        $user = $this->user();
        $user->createToken('phone');
        PasswordResetRequest::create(['user_id' => $user->id]);
        $token = Password::broker()->createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee('ravi@example.com');

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk()->assertSee('Password set');

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertFalse(PasswordResetRequest::where('user_id', $user->id)->pending()->exists());

        // The link works once.
        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Another#Pass2026',
            'password_confirmation' => 'Another#Pass2026',
        ])->assertOk()->assertSee('expired');
    }

    public function test_a_weak_or_mismatched_password_is_refused(): void
    {
        $user = $this->user();
        $original = $user->password;
        $token = Password::broker()->createToken($user);

        $this->from(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->post(route('password.update'), [
                'token' => $token,
                'email' => $user->email,
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertSessionHasErrors('password');

        $this->assertSame($original, $user->fresh()->password);
    }

    // ── Firebase email link ──────────────────────────────────────────────

    public function test_firebase_mode_without_setup_falls_back_to_the_laravel_email(): void
    {
        Notification::fake();
        $this->useMethod('firebase');
        config()->set('services.firebase.web_api_key', null);
        $user = $this->user();

        $this->forgot()->assertJsonPath('data.method', 'laravel');
        Notification::assertSentTo($user, ResetPassword::class);
        $this->getJson('/api/settings')->assertJsonPath('data.password_reset_method', 'laravel');
    }

    public function test_firebase_mode_has_firebase_send_the_email(): void
    {
        $this->configureFirebase();
        $this->useMethod('firebase');
        $user = $this->user();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test']),
            'identitytoolkit.googleapis.com/admin/v2/*' => Http::response([]),
            'identitytoolkit.googleapis.com/v1/projects/demo-project/accounts' => Http::response(['error' => ['message' => 'EMAIL_EXISTS']], 400),
            'identitytoolkit.googleapis.com/v1/accounts:sendOobCode*' => Http::response(['email' => 'ravi@example.com']),
        ]);

        $this->forgot()->assertJsonPath('data.method', 'firebase');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'accounts:sendOobCode?key=web-api-key')
            && $request['requestType'] === 'PASSWORD_RESET'
            && $request['email'] === 'ravi@example.com');

        // Firebase's email link is pointed at our page.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'admin/v2/projects/demo-project/config')
            && $request['notification']['sendEmail']['callbackUri'] === route('password.firebase.show'));

        $this->assertFalse(PasswordResetRequest::where('user_id', $user->id)->exists());
    }

    public function test_a_firebase_failure_is_logged_and_answers_the_same(): void
    {
        $this->configureFirebase();
        $this->useMethod('firebase');
        $user = $this->user();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test']),
            'identitytoolkit.googleapis.com/*' => Http::response(['error' => ['message' => 'CONFIGURATION_NOT_FOUND']], 400),
        ]);

        // Same answer to the app (it cannot tell this apart from success),
        // and nothing is queued for an admin — resets are self-service.
        $this->forgot()->assertJsonPath('data.method', 'firebase');
        $this->assertFalse(PasswordResetRequest::where('user_id', $user->id)->exists());
    }

    public function test_the_firebase_link_page_sets_the_laravel_password(): void
    {
        $this->configureFirebase();
        $user = $this->user();
        $user->createToken('phone');

        Http::fake([
            'identitytoolkit.googleapis.com/v1/accounts:resetPassword*' => Http::response([
                'email' => 'ravi@example.com',
                'requestType' => 'PASSWORD_RESET',
            ]),
        ]);

        $this->get(route('password.firebase.show', ['mode' => 'resetPassword', 'oobCode' => 'code-123']))
            ->assertOk()
            ->assertSee('ravi@example.com');

        $this->post(route('password.firebase.update'), [
            'oobCode' => 'code-123',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk()->assertSee('Password set');

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertSame(0, $user->tokens()->count());

        Http::assertSent(fn (Request $request) => ($request['oobCode'] ?? null) === 'code-123'
            && ($request['newPassword'] ?? null) === self::NEW_PASSWORD);
    }

    /**
     * The same link is the "set your password" email for an account someone
     * else created — worded as setting a password, not resetting one.
     */
    public function test_an_account_that_never_chose_a_password_is_asked_to_set_one(): void
    {
        $user = $this->user();
        $token = Password::broker()->createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee('Set your password')
            ->assertDontSee('Set a new password');
    }

    public function test_a_forgotten_password_is_still_worded_as_a_reset(): void
    {
        $user = $this->user();
        $user->forceFill(['must_change_password' => false])->save();
        $token = Password::broker()->createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee('Set a new password')
            ->assertDontSee('Set your password');

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk()->assertSee('Password changed');
    }

    public function test_an_expired_firebase_link_changes_nothing(): void
    {
        $this->configureFirebase();
        $user = $this->user();
        $original = $user->password;

        Http::fake([
            'identitytoolkit.googleapis.com/*' => Http::response(['error' => ['message' => 'EXPIRED_OOB_CODE']], 400),
        ]);

        $this->get(route('password.firebase.show', ['mode' => 'resetPassword', 'oobCode' => 'old']))
            ->assertOk()
            ->assertSee('expired');

        $this->post(route('password.firebase.update'), [
            'oobCode' => 'old',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk()->assertSee('expired');

        $this->assertSame($original, $user->fresh()->password);
    }

    public function test_the_app_is_told_which_method_is_in_force(): void
    {
        $this->useMethod('laravel');

        $this->getJson('/api/settings')->assertJsonPath('data.password_reset_method', 'laravel');
    }
}
