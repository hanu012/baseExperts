<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\PasswordResetService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Self-registration (vendor and customer apps). No password at sign-up:
 * the account is emailed a "set your password" link, and the first sign-in
 * happens after that — see AuthController::register.
 */
class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Asha Patel',
            'email' => 'asha@example.com',
            'role' => 'customer',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function vendorPayload(array $overrides = []): array
    {
        return $this->payload(array_merge([
            'role' => 'vendor',
            'business_name' => 'Cool Air Services',
            'phone' => '9812345678',
        ], $overrides));
    }

    /** What the emailed link's page does, without going through the page. */
    private function setPasswordFromLink(string $email = 'asha@example.com'): User
    {
        $user = User::where('email', $email)->sole();
        $user->resetPasswordFromEmailLink(self::PASSWORD);

        return $user->fresh();
    }

    public function test_a_customer_can_register_and_is_emailed_a_link(): void
    {
        $this->postJson('/api/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('error', null)
            ->assertJsonPath('data.user.email', 'asha@example.com')
            ->assertJsonPath('data.user.role', 'customer')
            ->assertJsonPath('data.password_link_sent_to', 'a***@example.com');

        $user = User::where('email', 'asha@example.com')->sole();

        $this->assertSame(UserRole::Customer, $user->role);
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_a_vendor_can_register_and_is_emailed_a_link(): void
    {
        $this->postJson('/api/auth/register', $this->vendorPayload())
            ->assertCreated()
            ->assertJsonPath('data.user.role', 'vendor')
            ->assertJsonPath('data.password_link_sent_to', 'a***@example.com');

        Notification::assertSentTo(User::where('email', 'asha@example.com')->sole(), ResetPassword::class);
    }

    /** No session until they have set a password and signed in. */
    public function test_registration_issues_no_token(): void
    {
        $this->postJson('/api/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonMissingPath('data.token');

        $this->assertSame(0, User::where('email', 'asha@example.com')->sole()->tokens()->count());
    }

    /**
     * Nobody holds the sign-up password, and the account counts as not
     * having chosen one yet — which is what makes the link's page say
     * "Set your password".
     */
    public function test_the_account_starts_with_a_password_nobody_holds(): void
    {
        $this->postJson('/api/auth/register', $this->payload([
            // An older app build still sending one: ignored.
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]))->assertCreated();

        $user = User::where('email', 'asha@example.com')->sole();

        $this->assertTrue($user->must_change_password);

        $this->postJson('/api/auth/login', [
            'email' => 'asha@example.com',
            'password' => self::PASSWORD,
            'device_name' => 'pixel-8',
        ])->assertStatus(401);
    }

    public function test_the_link_page_says_set_your_password(): void
    {
        $this->postJson('/api/auth/register', $this->payload())->assertCreated();

        $user = User::where('email', 'asha@example.com')->sole();
        $token = Password::broker()->createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee('Set your password');

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertOk()
            ->assertSee('Password set')
            ->assertSee('smcustomer://login');

        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_the_vendor_page_opens_the_vendor_app(): void
    {
        $this->postJson('/api/auth/register', $this->vendorPayload())->assertCreated();

        $user = User::where('email', 'asha@example.com')->sole();
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertOk()->assertSee('smvendor://login');
    }

    /** After the link: a normal sign-in, straight into the approval gate. */
    public function test_after_setting_the_password_the_user_signs_in(): void
    {
        $this->postJson('/api/auth/register', $this->vendorPayload())->assertCreated();
        $this->setPasswordFromLink();

        $this->postJson('/api/auth/login', [
            'email' => 'asha@example.com',
            'password' => self::PASSWORD,
            'device_name' => 'pixel-8',
        ])->assertOk()
            ->assertJsonPath('data.user.must_change_password', false)
            ->assertJsonPath('data.user.approval_status', 'pending');

        $user = User::where('email', 'asha@example.com')->sole();
        $this->assertSame(['role:vendor'], $user->tokens()->first()->abilities);
    }

    /**
     * The email is the only way in, so a sign-up whose email cannot go is
     * undone — the person sees an error and can try again with the same
     * email and phone.
     */
    public function test_if_the_email_cannot_be_sent_nothing_is_created(): void
    {
        $this->mock(PasswordResetService::class)
            ->shouldReceive('sendLink')
            ->andThrow(new \RuntimeException('mail server down'));

        $this->postJson('/api/auth/register', $this->vendorPayload())
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'PASSWORD_LINK_NOT_SENT');

        $this->assertDatabaseMissing('users', ['email' => 'asha@example.com']);
        $this->assertDatabaseCount('vendors', 0);
    }

    public function test_after_a_failed_email_the_same_details_can_register(): void
    {
        $this->mock(PasswordResetService::class)
            ->shouldReceive('sendLink')
            ->once()
            ->andThrow(new \RuntimeException('mail server down'));

        $this->postJson('/api/auth/register', $this->vendorPayload())->assertStatus(503);

        $this->forgetMock(PasswordResetService::class);

        $this->postJson('/api/auth/register', $this->vendorPayload())->assertCreated();
    }

    /**
     * Task 3.4 found self-registration created only a User row, leaving
     * self-service subscribe (task 4.2) nothing to attach a subscription
     * to. A vendor registration must also create a matching Vendor row,
     * in the same transaction, starting at the same `draft` status the
     * salesman-led flow starts at.
     */
    public function test_a_vendor_registration_creates_a_matching_vendor_row(): void
    {
        $this->postJson('/api/auth/register', $this->vendorPayload())->assertCreated();

        $user = User::where('email', 'asha@example.com')->firstOrFail();

        $this->assertDatabaseHas('vendors', [
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            // owner_name is not a separate field — it mirrors the account
            // name, same as the salesman-led flow already does.
            'owner_name' => 'Asha Patel',
            'phone' => '9812345678',
            'status' => 'draft',
            'created_by_salesman_id' => null,
        ]);
    }

    public function test_a_customer_registration_creates_no_vendor_row(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['role' => 'customer']))
            ->assertCreated();

        $this->assertDatabaseCount('vendors', 0);
    }

    /**
     * Task 4.6 found self-registration created only a User row for a
     * customer too, leaving location detection (SPEC section 4.2) nothing
     * to attach to. A customer registration must also create a matching
     * Customer row, in the same transaction — every field but user_id
     * stays null, populated later via GPS or the pincode fallback.
     */
    public function test_a_customer_registration_creates_a_matching_customer_row(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['role' => 'customer']))
            ->assertCreated();

        $user = User::where('email', 'asha@example.com')->firstOrFail();

        $this->assertDatabaseHas('customers', [
            'user_id' => $user->id,
            'phone' => null,
            'latitude' => null,
            'longitude' => null,
            'pincode' => null,
        ]);
    }

    public function test_a_vendor_registration_creates_no_customer_row(): void
    {
        $this->postJson('/api/auth/register', $this->vendorPayload())->assertCreated();

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_vendor_registration_requires_a_business_name(): void
    {
        $this->postJson('/api/auth/register', $this->vendorPayload(['business_name' => null]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertDatabaseMissing('users', ['email' => 'asha@example.com']);
        Notification::assertNothingSent();
    }

    public function test_vendor_registration_requires_a_phone(): void
    {
        $this->postJson('/api/auth/register', $this->vendorPayload(['phone' => null]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertDatabaseMissing('users', ['email' => 'asha@example.com']);
    }

    public function test_a_duplicate_vendor_phone_is_rejected(): void
    {
        $this->postJson('/api/auth/register', $this->vendorPayload())->assertCreated();

        $this->postJson('/api/auth/register', $this->vendorPayload([
            'email' => 'other@example.com',
            'business_name' => 'Someone Elses Shop',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_the_password_is_never_returned(): void
    {
        $this->postJson('/api/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonMissingPath('data.user.password');
    }

    public function test_registering_as_admin_is_rejected(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['role' => 'admin']))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertDatabaseMissing('users', ['email' => 'asha@example.com']);
    }

    public function test_registering_as_salesman_is_rejected(): void
    {
        // SPEC section 1: salesmen are created by an admin, never self-served.
        $this->postJson('/api/auth/register', $this->payload(['role' => 'salesman']))
            ->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'asha@example.com']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'asha@example.com']);

        $this->postJson('/api/auth/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_a_soft_deleted_email_cannot_be_reused(): void
    {
        // The unique index still holds the row, so validation must reject it
        // rather than letting the insert fail at the database level.
        $user = User::factory()->create(['email' => 'asha@example.com']);
        $user->delete();

        $this->postJson('/api/auth/register', $this->payload())
            ->assertStatus(422);
    }

    public function test_registration_errors_use_the_envelope(): void
    {
        $this->postJson('/api/auth/register', [])
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'data', 'error' => ['code', 'message', 'fields']]);
    }
}
