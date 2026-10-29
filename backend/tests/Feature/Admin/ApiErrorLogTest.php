<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\SuperAdmin\Resources\ApiErrorLogResource;
use App\Filament\SuperAdmin\Resources\ApiErrorLogResource\Pages\ListApiErrorLogs;
use App\Models\ApiErrorLog;
use App\Models\Setting;
use App\Models\User;
use Filament\Tables\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\ActsAsSuperAdmin;
use Tests\TestCase;

/**
 * Settings → Error log and System → API error log: failed api/* calls are
 * recorded by the LogApiErrors middleware only while the setting is on, at
 * the chosen level, with secrets blanked.
 */
class ApiErrorLogTest extends TestCase
{
    use ActsAsSuperAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Throwaway API routes that fail on purpose.
        Route::middleware('api')->prefix('api/_test')->group(function () {
            Route::get('/boom', fn () => throw new RuntimeException('Payment table is locked'));
            Route::post('/validate', fn (Request $request) => $request->validate([
                'email' => ['required', 'email'],
            ]));
            Route::get('/ok', fn () => ['ok' => true]);
            Route::get('/ok-envelope', fn () => \App\Http\Responses\ApiResponse::success(['note' => 'success:false in text only']));
            // A failure sent with HTTP 200 — the apps still treat it as one.
            Route::get('/soft-fail', fn () => response()->json([
                'success' => false,
                'data' => null,
                'error' => ['code' => 'QUOTA_FULL', 'message' => 'No zone slots left on this plan.'],
            ]));
        });
    }

    private function logging(bool $enabled, string $level = 'server'): void
    {
        Setting::where('key', 'api_error_log_enabled')->update(['value' => $enabled ? 'true' : 'false']);
        Setting::where('key', 'api_error_log_level')->update(['value' => $level]);
    }

    // ── Recording ────────────────────────────────────────────────────────

    public function test_nothing_is_recorded_while_switched_off(): void
    {
        $this->logging(false);

        $this->getJson('/api/_test/boom')->assertStatus(500);

        $this->assertSame(0, ApiErrorLog::count());
    }

    public function test_a_server_error_is_recorded_with_its_exception(): void
    {
        $this->logging(true);

        $this->getJson('/api/_test/boom')->assertStatus(500);

        $log = ApiErrorLog::sole();
        $this->assertSame(500, $log->status);
        $this->assertSame('GET', $log->method);
        $this->assertSame('/api/_test/boom', $log->path);
        $this->assertSame(RuntimeException::class, $log->exception_class);
        $this->assertStringContainsString('Payment table is locked', $log->message);
        $this->assertNotEmpty($log->trace);
    }

    public function test_server_level_skips_request_errors_and_all_level_records_them(): void
    {
        $this->logging(true, 'server');
        $this->postJson('/api/_test/validate', [])->assertStatus(422);
        $this->assertSame(0, ApiErrorLog::count());

        $this->logging(true, 'all');
        $this->postJson('/api/_test/validate', [])->assertStatus(422);

        $log = ApiErrorLog::sole();
        $this->assertSame(422, $log->status);
        $this->assertStringContainsString('email', $log->message);
    }

    public function test_an_unknown_api_url_is_recorded_at_all_level(): void
    {
        $this->logging(true, 'all');

        $this->getJson('/api/_test/does-not-exist')->assertStatus(404);

        $this->assertSame(404, ApiErrorLog::sole()->status);
    }

    public function test_successful_calls_are_never_recorded(): void
    {
        $this->logging(true, 'all');

        $this->getJson('/api/_test/ok')->assertOk();
        // The words "success:false" inside a successful reply's data must
        // not trip the check — only the envelope's own flag counts.
        $this->getJson('/api/_test/ok-envelope')->assertOk();

        $this->assertSame(0, ApiErrorLog::count());
    }

    /** At both levels: a failure hidden behind a 200 is the easiest to miss. */
    public function test_a_success_false_reply_with_status_200_is_recorded_at_either_level(): void
    {
        foreach (['server', 'all'] as $level) {
            ApiErrorLog::query()->delete();
            $this->logging(true, $level);

            $this->getJson('/api/_test/soft-fail')->assertOk()->assertJsonPath('success', false);

            $log = ApiErrorLog::sole();
            $this->assertSame(200, $log->status, $level);
            $this->assertSame('QUOTA_FULL', $log->error_code, $level);
            $this->assertSame('No zone slots left on this plan.', $log->message, $level);
        }
    }

    public function test_success_false_replies_have_their_own_filter(): void
    {
        $this->logging(true);
        $this->actingAs(User::factory()->role(UserRole::Admin)->create());

        $soft = ApiErrorLog::create(['status' => 200, 'method' => 'POST', 'path' => '/api/a', 'error_code' => 'QUOTA_FULL']);
        $server = ApiErrorLog::create(['status' => 500, 'method' => 'GET', 'path' => '/api/b']);

        Livewire::test(ListApiErrorLogs::class)
            ->filterTable('kind', 'reported')
            ->assertCanSeeTableRecords([$soft])
            ->assertCanNotSeeTableRecords([$server]);
    }

    public function test_passwords_tokens_and_secrets_are_never_stored(): void
    {
        $this->logging(true, 'all');

        $this->postJson('/api/_test/validate', [
            'email' => 'not-an-email',
            'password' => 'hunter2-secret',
            'device_token' => 'fcm-abc',
            'nested' => ['razorpay_key_secret' => 'rzp-secret', 'name' => 'Ravi'],
        ])->assertStatus(422);

        $payload = ApiErrorLog::sole()->request_payload;

        $this->assertSame('not-an-email', $payload['email']);
        $this->assertSame('[hidden]', $payload['password']);
        $this->assertSame('[hidden]', $payload['device_token']);
        $this->assertSame('[hidden]', $payload['nested']['razorpay_key_secret']);
        $this->assertSame('Ravi', $payload['nested']['name']);
        $this->assertStringNotContainsString('hunter2', json_encode($payload));
    }

    public function test_the_signed_in_user_is_recorded(): void
    {
        $this->logging(true);
        $user = User::factory()->role(UserRole::Customer)->create(['must_change_password' => false]);
        $token = $user->createToken('phone')->plainTextToken;

        $this->withToken($token)->getJson('/api/_test/boom')->assertStatus(500);

        $this->assertSame($user->id, ApiErrorLog::sole()->user_id);
    }

    public function test_entries_older_than_the_retention_period_are_pruned(): void
    {
        $old = ApiErrorLog::create(['status' => 500, 'method' => 'GET', 'path' => '/api/old']);
        $old->forceFill(['created_at' => now()->subDays(ApiErrorLog::RETENTION_DAYS + 1)])->save();
        $recent = ApiErrorLog::create(['status' => 500, 'method' => 'GET', 'path' => '/api/recent']);

        $this->artisan('model:prune', ['--model' => [ApiErrorLog::class]])->assertSuccessful();

        $this->assertNull(ApiErrorLog::find($old->id));
        $this->assertNotNull(ApiErrorLog::find($recent->id));
    }

    // ── Admin page ───────────────────────────────────────────────────────

    public function test_the_page_exists_only_while_the_log_is_on(): void
    {
        $this->actingAsVerifiedSuperAdmin();

        $this->logging(false);
        $this->assertFalse(ApiErrorLogResource::shouldRegisterNavigation());
        $this->get(ApiErrorLogResource::getUrl('index'))->assertForbidden();

        $this->logging(true);
        $this->assertTrue(ApiErrorLogResource::shouldRegisterNavigation());
        $this->get(ApiErrorLogResource::getUrl('index'))->assertOk();
    }

    public function test_the_list_shows_logged_errors_and_filters_by_type(): void
    {
        $this->logging(true, 'all');
        $this->actingAsVerifiedSuperAdmin();

        $server = ApiErrorLog::create(['status' => 500, 'method' => 'GET', 'path' => '/api/boom', 'message' => 'Payment table is locked']);
        $client = ApiErrorLog::create(['status' => 422, 'method' => 'POST', 'path' => '/api/leads', 'error_code' => 'VALIDATION_FAILED']);

        Livewire::test(ListApiErrorLogs::class)
            ->assertCanSeeTableRecords([$server, $client])
            ->filterTable('kind', 'server')
            ->assertCanSeeTableRecords([$server])
            ->assertCanNotSeeTableRecords([$client]);

        Livewire::test(ListApiErrorLogs::class)
            ->mountTableAction('details', $server)
            ->assertSee('Payment table is locked');
    }

    /** It moved to /super-admin: no sub-admin can open it, whatever they hold. */
    public function test_only_super_admins_can_open_or_clear_it(): void
    {
        $this->logging(true);
        $log = ApiErrorLog::create(['status' => 500, 'method' => 'GET', 'path' => '/api/boom']);

        $this->actingAsVerifiedSuperAdmin();
        Livewire::test(ListApiErrorLogs::class)
            ->assertSuccessful()
            ->assertTableActionVisible(DeleteAction::class, $log)
            ->assertActionVisible('clearAll');

        $this->actingAs(User::factory()->subAdmin(['logs.viewAny', 'settings.viewAny', 'settings.update'])->create());
        $this->assertFalse(ApiErrorLogResource::canAccess());
        $this->get(ApiErrorLogResource::getUrl('index'))->assertForbidden();
    }

    public function test_clear_all_empties_the_log(): void
    {
        $this->logging(true);
        $this->actingAsVerifiedSuperAdmin();
        ApiErrorLog::create(['status' => 500, 'method' => 'GET', 'path' => '/api/a']);
        ApiErrorLog::create(['status' => 500, 'method' => 'GET', 'path' => '/api/b']);

        Livewire::test(ListApiErrorLogs::class)->callAction('clearAll');

        $this->assertSame(0, ApiErrorLog::count());
    }
}
