<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Category;
use App\Models\Plan;
use App\Models\PlanQuota;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings → Sign-in = Firebase (AuthProvider, AccountInviteService).
 * Firebase is faked with Http::fake(); no network.
 *
 * The first test pins the promise that matters most: with the default
 * (Laravel) nothing calls Firebase and nothing behaves differently.
 */
class FirebaseSignInTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct#Horse2026';

    private const NEW_PASSWORD = 'BrandNew#Pass2026';

    private function useFirebase(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        config()->set('services.fcm.project_id', 'demo-project');
        config()->set('services.fcm.client_email', 'firebase-adminsdk@demo-project.iam.gserviceaccount.com');
        config()->set('services.fcm.private_key_base64', base64_encode($pem));
        config()->set('services.firebase.web_api_key', 'web-api-key');

        Setting::where('key', 'auth_provider')->update(['value' => 'firebase']);
    }

    /**
     * Every Firebase endpoint answering "OK". Overrides go first, since the
     * first matching pattern wins.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakeFirebase(array $overrides = []): void
    {
        $api = 'identitytoolkit.googleapis.com/v1';

        Http::fake($overrides + [
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test']),
            'identitytoolkit.googleapis.com/admin/v2/*' => Http::response([]),
            "{$api}/accounts:signInWithPassword*" => Http::response(['localId' => 'fb-signed-in']),
            "{$api}/accounts:sendOobCode*" => Http::response(['email' => 'x@example.com']),
            "{$api}/projects/demo-project/accounts" => Http::response(['localId' => 'fb-created']),
            "{$api}/projects/demo-project/accounts:lookup" => Http::response(['users' => [['localId' => 'fb-found']]]),
            "{$api}/projects/demo-project/accounts:update" => Http::response(['localId' => 'fb-updated']),
            "{$api}/projects/demo-project/accounts:delete" => Http::response([]),
        ]);
    }

    private function refuseSignIn(): array
    {
        return [
            'identitytoolkit.googleapis.com/v1/accounts:signInWithPassword*' => Http::response(['error' => ['message' => 'INVALID_LOGIN_CREDENTIALS']], 400),
        ];
    }

    private function customer(array $attributes = []): User
    {
        return User::factory()->role(UserRole::Customer)->create($attributes + [
            'email' => 'ravi@example.com',
            'password' => Hash::make(self::PASSWORD),
            'must_change_password' => false,
        ]);
    }

    private function login(string $password = self::PASSWORD): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/login', [
            'email' => 'ravi@example.com',
            'password' => $password,
            'device_name' => 'phone',
        ]);
    }

    // ── Default: Laravel ─────────────────────────────────────────────────

    public function test_by_default_sign_in_never_touches_firebase(): void
    {
        Http::fake();
        $this->customer();

        $this->login()->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
        $this->login('wrong-password')->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_firebase_chosen_but_not_set_up_stays_with_laravel(): void
    {
        Http::fake();
        Setting::where('key', 'auth_provider')->update(['value' => 'firebase']);
        $this->customer();

        $this->login()->assertOk();

        Http::assertNothingSent();
    }

    // ── Sign-in ──────────────────────────────────────────────────────────

    public function test_firebase_checks_the_password_and_the_account_is_linked(): void
    {
        $this->useFirebase();
        $this->fakeFirebase();
        $user = $this->customer();

        $this->login()->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'accounts:signInWithPassword?key=web-api-key')
            && $request['email'] === 'ravi@example.com'
            && $request['password'] === self::PASSWORD);
        $this->assertSame('fb-signed-in', $user->fresh()->firebase_uid);
    }

    /** Firebase is the newer copy here (local hash out of date) — local catches up. */
    public function test_a_password_firebase_accepts_becomes_the_local_one_too(): void
    {
        $this->useFirebase();
        $this->fakeFirebase();
        $user = $this->customer(['password' => Hash::make('Some#OldPass2020')]);

        $this->login()->assertOk();

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    /**
     * An invitee who set their password on Firebase's own page (our action
     * URL not set) is not sent back to change-password at sign-in.
     */
    public function test_a_password_set_on_firebases_own_page_clears_must_change_password(): void
    {
        $this->useFirebase();
        $this->fakeFirebase();
        $user = $this->customer([
            'password' => Hash::make('Temp#Given2026'),
            'must_change_password' => true,
        ]);

        $this->login()->assertOk()->assertJsonPath('data.user.must_change_password', false);

        $this->assertFalse($user->fresh()->must_change_password);
    }

    /** Signing in with the temporary password itself leaves the flag set. */
    public function test_the_temporary_password_still_requires_a_change(): void
    {
        $this->useFirebase();
        $this->fakeFirebase();
        $user = $this->customer(['must_change_password' => true]);

        $this->login()->assertOk()->assertJsonPath('data.user.must_change_password', true);

        $this->assertTrue($user->fresh()->must_change_password);
    }

    /** Not moved to Firebase yet: the local password signs in and moves it over. */
    public function test_an_account_not_in_firebase_yet_moves_over_at_sign_in(): void
    {
        $this->useFirebase();
        $this->fakeFirebase($this->refuseSignIn());
        $user = $this->customer();

        $this->login()->assertOk();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/projects/demo-project/accounts')
            && $request['email'] === 'ravi@example.com'
            && $request['password'] === self::PASSWORD);
        $this->assertSame('fb-created', $user->fresh()->firebase_uid);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->useFirebase();
        $this->fakeFirebase($this->refuseSignIn());
        $user = $this->customer();

        $this->login('Wrong#Pass2026')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->assertNull($user->fresh()->firebase_uid);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/projects/demo-project/accounts'));
    }

    public function test_sign_in_still_works_when_firebase_is_down(): void
    {
        $this->useFirebase();
        $this->fakeFirebase([
            'identitytoolkit.googleapis.com/v1/accounts:signInWithPassword*' => Http::failedConnection(),
        ]);
        $this->customer();

        $this->login()->assertOk();
        $this->login('Wrong#Pass2026')->assertStatus(401);
    }

    /** Approval is untouched: an unapproved account still gets the pending flow. */
    public function test_the_approval_gate_is_unchanged(): void
    {
        $this->useFirebase();
        $this->fakeFirebase();
        $this->customer()->forceFill(['approval_status' => 'pending'])->saveQuietly();

        $token = $this->login()->assertOk()->json('data.token');

        $this->withToken($token)->patchJson('/api/user/preferences', ['language' => 'en'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_PENDING_APPROVAL');
    }

    // ── Sign-up and password changes ─────────────────────────────────────

    /**
     * No password at sign-up: Firebase gets a password-less account and
     * sends its "set your password" email, which opens our page.
     */
    public function test_sign_up_creates_the_user_here_and_firebase_emails_the_link(): void
    {
        $this->useFirebase();
        $this->fakeFirebase();

        $this->postJson('/api/auth/register', [
            'name' => 'Ravi Patel',
            'email' => 'ravi@example.com',
            'role' => 'customer',
        ])->assertCreated()
            ->assertJsonPath('data.password_link_sent_to', 'r***@example.com');

        $user = User::where('email', 'ravi@example.com')->sole();

        $this->assertNotNull($user->customer);
        $this->assertTrue($user->must_change_password);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/projects/demo-project/accounts')
            && ! isset($request['password']));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'accounts:sendOobCode')
            && $request['requestType'] === 'PASSWORD_RESET'
            && $request['email'] === 'ravi@example.com');
    }

    /** The email is the only way in: if Firebase cannot send it, nothing is kept. */
    public function test_sign_up_is_undone_if_firebase_cannot_send_the_email(): void
    {
        $this->useFirebase();
        $this->fakeFirebase([
            'identitytoolkit.googleapis.com/v1/accounts:sendOobCode*' => Http::response(['error' => ['message' => 'INTERNAL']], 500),
        ]);

        $this->postJson('/api/auth/register', [
            'name' => 'Ravi Patel',
            'email' => 'ravi@example.com',
            'role' => 'customer',
        ])->assertStatus(503)
            ->assertJsonPath('error.code', 'PASSWORD_LINK_NOT_SENT');

        $this->assertDatabaseMissing('users', ['email' => 'ravi@example.com']);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_changing_the_password_updates_firebase_too(): void
    {
        $this->useFirebase();
        $this->fakeFirebase([
            'identitytoolkit.googleapis.com/v1/accounts:signInWithPassword*' => Http::response(['localId' => 'fb-uid-1']),
        ]);
        $user = $this->customer(['firebase_uid' => 'fb-uid-1']);

        $this->actingAs($user, 'sanctum')->postJson('/api/auth/change-password', [
            'current_password' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'accounts:update')
            && $request['localId'] === 'fb-uid-1'
            && $request['password'] === self::NEW_PASSWORD);
    }

    public function test_forgot_password_always_uses_firebase_with_firebase_sign_in(): void
    {
        $this->useFirebase();
        $this->fakeFirebase();
        Setting::where('key', 'password_reset_method')->update(['value' => 'laravel']);
        $this->customer();

        $this->postJson('/api/auth/password-reset-requests', ['email' => 'ravi@example.com'])
            ->assertOk()
            ->assertJsonPath('data.method', 'firebase');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'accounts:sendOobCode'));
    }

    // ── Keeping Firebase in step ─────────────────────────────────────────

    public function test_an_email_change_reaches_firebase(): void
    {
        $this->useFirebase();
        $this->fakeFirebase();
        $user = $this->customer(['firebase_uid' => 'fb-uid-1']);

        $user->update(['email' => 'ravi.new@example.com']);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'accounts:update')
            && $request['localId'] === 'fb-uid-1'
            && $request['email'] === 'ravi.new@example.com');
    }

    public function test_deleting_a_user_removes_the_firebase_account(): void
    {
        $this->useFirebase();
        $this->fakeFirebase();
        $user = $this->customer(['firebase_uid' => 'fb-uid-1']);

        $user->deleteWithTombstone();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'accounts:delete')
            && $request['localId'] === 'fb-uid-1');
        // The tombstone address never reaches Firebase.
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), 'accounts:update'));
        $this->assertNull(User::withTrashed()->find($user->id)->firebase_uid);
    }

    // ── "Set your password" links ────────────────────────────────────────

    private function salesmanSells(): array
    {
        $salesmanUser = User::factory()->role(UserRole::Salesman)->create(['must_change_password' => false]);
        $salesman = Salesman::create([
            'user_id' => $salesmanUser->id,
            'employee_code' => 'EMP-1',
            'phone' => '9900000001',
            'commission_rate_bps' => 1000,
        ]);

        $vendor = Vendor::create([
            'user_id' => User::factory()->role(UserRole::Vendor)->create(['email' => 'vendor@example.com'])->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Bhavin Patel',
            'phone' => '9812345678',
            'status' => 'draft',
            'created_by_salesman_id' => $salesman->id,
        ]);

        $plan = Plan::factory()->create(['price_paise' => 99_900, 'duration_days' => 365]);
        PlanQuota::where('plan_id', $plan->id)->update(['max_categories' => 5, 'max_subcategories' => 15, 'max_zones' => 5]);
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->for($category)->create();
        $zone = Zone::factory()->active()->create();

        $response = $this->actingAs($salesmanUser, 'sanctum')->postJson('/api/subscriptions', [
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'category_ids' => [$category->id],
            'subcategory_ids' => [$subcategory->id],
            'zone_ids' => [$zone->id],
            'payment_mode' => 'cash',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        return [$response, $vendor, $salesmanUser];
    }

    public function test_by_default_the_salesman_still_gets_a_temporary_password(): void
    {
        Http::fake();

        [$response] = $this->salesmanSells();

        $this->assertNotEmpty($response->json('data.temporary_password'));
        $this->assertNull($response->json('data.password_link_sent_to'));
        Http::assertNothingSent();
    }

    public function test_a_sold_vendor_is_emailed_a_set_password_link(): void
    {
        $this->useFirebase();
        $this->fakeFirebase([
            'identitytoolkit.googleapis.com/v1/projects/demo-project/accounts' => Http::response(['localId' => 'fb-vendor']),
        ]);

        [$response, $vendor] = $this->salesmanSells();

        $response->assertJsonPath('data.temporary_password', null)
            ->assertJsonPath('data.password_link_sent_to', 'v***@example.com')
            ->assertJsonPath('data.vendor.status', 'active');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'accounts:sendOobCode')
            && $request['requestType'] === 'PASSWORD_RESET'
            && $request['email'] === 'vendor@example.com');

        $vendorUser = $vendor->user->fresh();
        $this->assertSame('fb-vendor', $vendorUser->firebase_uid);
        // Cleared only once they set their password from the link.
        $this->assertTrue($vendorUser->must_change_password);
    }

    /** Onboarding never stalls on Firebase: no email → the old temporary password. */
    public function test_if_the_email_fails_the_salesman_gets_a_temporary_password(): void
    {
        $this->useFirebase();
        $this->fakeFirebase([
            'identitytoolkit.googleapis.com/v1/accounts:sendOobCode*' => Http::response(['error' => ['message' => 'INTERNAL']], 500),
        ]);

        [$response, $vendor] = $this->salesmanSells();

        $temporary = $response->json('data.temporary_password');
        $this->assertNotEmpty($temporary);
        $this->assertNull($response->json('data.password_link_sent_to'));
        $this->assertTrue(Hash::check($temporary, $vendor->user->fresh()->password));
    }

    public function test_the_salesman_can_resend_the_link(): void
    {
        $this->useFirebase();
        $this->fakeFirebase();

        [, $vendor, $salesmanUser] = $this->salesmanSells();

        $this->actingAs($salesmanUser, 'sanctum')
            ->postJson("/api/vendors/{$vendor->id}/password-link")
            ->assertOk()
            ->assertJsonPath('data.password_link_sent_to', 'v***@example.com');
    }

    public function test_no_link_once_the_vendor_has_chosen_a_password(): void
    {
        $this->useFirebase();
        $this->fakeFirebase();

        [, $vendor, $salesmanUser] = $this->salesmanSells();
        $vendor->user->resetPasswordFromEmailLink(self::NEW_PASSWORD);

        $this->actingAs($salesmanUser, 'sanctum')
            ->postJson("/api/vendors/{$vendor->id}/password-link")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PASSWORD_LINK_UNAVAILABLE');
    }

    public function test_another_salesman_cannot_send_the_link(): void
    {
        $this->useFirebase();
        $this->fakeFirebase();

        [, $vendor] = $this->salesmanSells();

        $other = User::factory()->role(UserRole::Salesman)->create(['must_change_password' => false]);
        Salesman::create(['user_id' => $other->id, 'employee_code' => 'EMP-2', 'phone' => '9900000002']);

        $this->actingAs($other->fresh(), 'sanctum')
            ->postJson("/api/vendors/{$vendor->id}/password-link")
            ->assertForbidden();
    }

    public function test_an_admin_created_salesman_is_emailed_a_link(): void
    {
        $this->useFirebase();
        $this->fakeFirebase();
        $this->actingAs(User::factory()->role(UserRole::Admin)->create());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Riya Patel',
                'email' => 'riya@example.com',
                'role' => 'salesman',
                'salesman' => ['employee_code' => 'EMP-009', 'phone' => '9900000009', 'is_active' => true],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $titles = collect(session('filament.notifications'))->pluck('title');

        $this->assertContains('Password link sent', $titles);
        $this->assertFalse($titles->contains(fn ($title) => str_starts_with((string) $title, 'Temporary password for')));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'accounts:sendOobCode')
            && $request['email'] === 'riya@example.com');

        $user = User::where('email', 'riya@example.com')->sole();
        $this->assertTrue($user->isApproved());
        $this->assertTrue($user->must_change_password);
    }

    public function test_the_users_list_offers_send_password_link_only_while_needed(): void
    {
        $this->useFirebase();
        $this->fakeFirebase();
        $this->actingAs(User::factory()->role(UserRole::Admin)->create());

        $waiting = User::factory()->role(UserRole::Salesman)->create(['must_change_password' => true]);
        $settled = User::factory()->role(UserRole::Salesman)->create(['must_change_password' => false]);

        Livewire::test(ListUsers::class)
            ->assertTableActionVisible('sendPasswordLink', $waiting)
            ->assertTableActionHidden('sendPasswordLink', $settled)
            ->callTableAction('sendPasswordLink', $waiting);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'accounts:sendOobCode')
            && $request['email'] === $waiting->email);
    }

    public function test_the_users_list_has_no_password_link_with_laravel_sign_in(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create());
        $waiting = User::factory()->role(UserRole::Salesman)->create(['must_change_password' => true]);

        Livewire::test(ListUsers::class)->assertTableActionHidden('sendPasswordLink', $waiting);
    }
}
