<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\SuperAdmin\Pages\SystemHealthPage;
use App\Filament\SuperAdmin\Pages\TwoFactorChallenge;
use App\Filament\SuperAdmin\Pages\TwoFactorSetup;
use App\Filament\SuperAdmin\Resources\ActivityLogResource\Pages\ListActivityLogs;
use App\Filament\SuperAdmin\Resources\AdminResource\Pages\CreateAdmin;
use App\Filament\SuperAdmin\Resources\AdminResource\Pages\EditAdmin;
use App\Filament\SuperAdmin\Resources\AdminResource\Pages\ListAdmins;
use App\Http\Middleware\RequireSuperAdminTwoFactor;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ActsAsSuperAdmin;
use Tests\TestCase;

/**
 * /super-admin: who gets in, two-step login, the Admins screen and its
 * safety rules, activity log and system health.
 */
class SuperAdminPanelTest extends TestCase
{
    use ActsAsSuperAdmin;
    use RefreshDatabase;

    // ── Who gets in ──────────────────────────────────────────────────────

    public function test_a_sub_admin_cannot_open_the_super_admin_panel(): void
    {
        $this->actingAs(User::factory()->subAdmin(['users.viewAny', 'settings.viewAny', 'settings.update'])->create());

        $this->get('/super-admin')->assertForbidden();
        // …but still uses /admin as before.
        $this->get('/admin')->assertOk();
    }

    public function test_apps_users_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Vendor)->create());

        $this->get('/super-admin')->assertForbidden();
    }

    // ── Two-step login ───────────────────────────────────────────────────

    public function test_a_super_admin_without_two_step_login_must_set_it_up_first(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create());

        $this->get('/super-admin')->assertRedirect(TwoFactorSetup::getUrl(panel: 'super-admin'));
        $this->get('/super-admin/admins')->assertRedirect(TwoFactorSetup::getUrl(panel: 'super-admin'));
        // /admin is not affected.
        $this->get('/admin')->assertOk();
    }

    public function test_setup_turns_it_on_with_a_correct_code_and_shows_recovery_codes(): void
    {
        $user = User::factory()->role(UserRole::Admin)->create();
        $this->actingAs($user);
        $this->useSuperAdminPanel();

        $page = Livewire::test(TwoFactorSetup::class);
        $secret = $user->fresh()->two_factor_secret;
        $this->assertNotEmpty($secret);
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled(), 'a pending setup must not lock anyone out');

        $page->fillForm(['code' => '000000'])->call('confirm')->assertHasErrors(['data.code']);

        $page->fillForm(['code' => Totp::codeAt($secret, intdiv(time(), 30))])
            ->call('confirm')
            ->assertHasNoErrors();

        $this->assertCount(8, $page->get('recoveryCodes'));
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->assertTrue(RequireSuperAdminTwoFactor::isFresh());
    }

    public function test_with_two_step_login_on_a_new_session_is_asked_for_a_code(): void
    {
        $this->actingAs($this->superAdminWithTwoFactor());

        $this->get('/super-admin/admins')->assertRedirect(TwoFactorChallenge::getUrl(panel: 'super-admin'));
    }

    public function test_the_code_page_lets_you_in_and_refuses_the_same_code_twice(): void
    {
        $user = $this->superAdminWithTwoFactor();
        $this->actingAs($user);
        $this->useSuperAdminPanel();
        $code = Totp::codeAt($user->two_factor_secret, intdiv(time(), 30));

        Livewire::test(TwoFactorChallenge::class)
            ->fillForm(['code' => $code])
            ->call('verify')
            ->assertHasNoErrors();

        $this->assertTrue(RequireSuperAdminTwoFactor::isFresh());

        // Replayed — someone who saw the first use.
        $this->assertFalse(app(TwoFactorService::class)->verify($user->fresh(), $code));
    }

    public function test_a_recovery_code_works_once(): void
    {
        $user = $this->superAdminWithTwoFactor();

        $this->assertTrue(app(TwoFactorService::class)->verify($user, 'aaaaa-bbbbb'));
        $this->assertFalse(app(TwoFactorService::class)->verify($user->fresh(), 'aaaaa-bbbbb'));
    }

    public function test_after_30_idle_minutes_the_code_is_asked_for_again(): void
    {
        $this->actingAsVerifiedSuperAdmin();
        $this->get('/super-admin/admins')->assertOk();

        $this->withSession([RequireSuperAdminTwoFactor::LAST_SEEN => now()->subMinutes(31)->getTimestamp()]);

        $this->get('/super-admin/admins')->assertRedirect(TwoFactorChallenge::getUrl(panel: 'super-admin'));
    }

    public function test_the_secret_and_recovery_codes_never_reach_the_audit_log(): void
    {
        $user = User::factory()->role(UserRole::Admin)->create();
        $secret = app(TwoFactorService::class)->begin($user);
        app(TwoFactorService::class)->confirm($user, Totp::codeAt($secret, intdiv(time(), 30)));

        $log = AuditLog::query()->get()->toJson();

        $this->assertStringNotContainsString('two_factor_secret', $log);
        $this->assertStringNotContainsString('two_factor_recovery_codes', $log);
        $this->assertStringContainsString('two_factor_confirmed_at', $log);
    }

    // ── Admins ───────────────────────────────────────────────────────────

    public function test_the_admins_screen_lists_admins_only(): void
    {
        $me = $this->actingAsVerifiedSuperAdmin();
        $subAdmin = User::factory()->subAdmin(['users.viewAny'])->create();
        $vendor = User::factory()->role(UserRole::Vendor)->create();

        Livewire::test(ListAdmins::class)
            ->assertCanSeeTableRecords([$me, $subAdmin])
            ->assertCanNotSeeTableRecords([$vendor]);
    }

    public function test_a_sub_admin_is_created_from_a_template(): void
    {
        $this->actingAsVerifiedSuperAdmin();

        Livewire::test(CreateAdmin::class)
            ->fillForm(['name' => 'Asha', 'email' => 'asha@example.com', 'template' => 'accounts'])
            ->call('create')
            ->assertHasNoFormErrors();

        $asha = User::where('email', 'asha@example.com')->sole();

        $this->assertSame(UserRole::Admin, $asha->role);
        $this->assertFalse($asha->isSuperAdmin());
        $this->assertContains('payments.verify', $asha->permissions);
        $this->assertTrue($asha->isApproved());
        $this->assertTrue($asha->must_change_password);
    }

    public function test_a_super_admin_is_stored_as_the_wildcard(): void
    {
        $this->actingAsVerifiedSuperAdmin();

        Livewire::test(CreateAdmin::class)
            ->fillForm(['name' => 'Ravi', 'email' => 'ravi@example.com', 'is_super_admin' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame([Permission::WILDCARD], User::where('email', 'ravi@example.com')->sole()->permissions);
    }

    public function test_made_up_permissions_are_dropped(): void
    {
        $this->actingAsVerifiedSuperAdmin();
        $sub = User::factory()->subAdmin(['users.viewAny'])->create();

        Livewire::test(EditAdmin::class, ['record' => $sub->getKey()])
            ->fillForm(['permissions' => ['users.viewAny', 'not.a.permission']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['users.viewAny'], $sub->fresh()->permissions);
    }

    public function test_a_super_admin_can_be_downgraded_while_another_remains(): void
    {
        $this->actingAsVerifiedSuperAdmin();
        $target = $this->superAdminWithTwoFactor();

        Livewire::test(EditAdmin::class, ['record' => $target->getKey()])
            ->fillForm(['is_super_admin' => false, 'permissions' => ['users.viewAny']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['users.viewAny'], $target->fresh()->permissions);
    }

    public function test_refuses_to_leave_no_super_admin(): void
    {
        $me = $this->actingAsVerifiedSuperAdmin();
        $other = $this->superAdminWithTwoFactor();

        // "me" lost super-admin in the database (another session, or by
        // hand) but this signed-in copy still has it — so "other" is now the
        // last real super-admin, and taking it away must be refused.
        \Illuminate\Support\Facades\DB::table('users')->where('id', $me->id)->update(['permissions' => json_encode(['users.viewAny'])]);

        Livewire::test(EditAdmin::class, ['record' => $other->getKey()])
            ->fillForm(['is_super_admin' => false])
            ->call('save');

        $this->assertTrue($other->fresh()->isSuperAdmin());
    }

    public function test_nobody_can_edit_their_own_admin_account_here(): void
    {
        $me = $this->actingAsVerifiedSuperAdmin();

        Livewire::test(ListAdmins::class)->assertTableActionHidden('edit', $me);
        $this->get(\App\Filament\SuperAdmin\Resources\AdminResource::getUrl('edit', ['record' => $me]))->assertForbidden();
    }

    public function test_delete_is_refused_for_yourself_and_allowed_for_another_admin(): void
    {
        $me = $this->actingAsVerifiedSuperAdmin();
        $sub = User::factory()->subAdmin(['users.viewAny'])->create();

        Livewire::test(ListAdmins::class)
            ->assertTableActionDisabled('deleteAdmin', $me)
            ->callTableAction('deleteAdmin', $sub);

        $this->assertNull($me->fresh()->deleted_at);
        $this->assertTrue(User::withTrashed()->find($sub->id)->trashed());
    }

    public function test_sign_out_everywhere_revokes_tokens(): void
    {
        $this->actingAsVerifiedSuperAdmin();
        $sub = User::factory()->subAdmin(['users.viewAny'])->create();
        $sub->createToken('phone');

        Livewire::test(ListAdmins::class)->callTableAction('signOutEverywhere', $sub);

        $this->assertSame(0, $sub->tokens()->count());
    }

    public function test_reset_two_step_login_for_a_lost_phone(): void
    {
        $this->actingAsVerifiedSuperAdmin();
        $other = $this->superAdminWithTwoFactor();

        Livewire::test(ListAdmins::class)->callTableAction('resetTwoFactor', $other);

        $this->assertFalse($other->fresh()->hasTwoFactorEnabled());
    }

    public function test_the_console_command_resets_two_step_login(): void
    {
        $user = $this->superAdminWithTwoFactor(['email' => 'boss@example.com']);

        $this->artisan('admin:reset-two-factor', ['email' => 'boss@example.com'])->assertSuccessful();

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    // ── Activity log, system health, dashboard ───────────────────────────

    public function test_the_activity_log_lists_changes_and_cannot_change_them(): void
    {
        $this->actingAsVerifiedSuperAdmin();
        $vendor = User::factory()->role(UserRole::Vendor)->create();
        $vendor->update(['name' => 'Renamed Vendor']);

        $entry = AuditLog::query()->where('auditable_id', $vendor->id)->where('action', 'updated')->firstOrFail();

        Livewire::test(ListActivityLogs::class)
            ->assertCanSeeTableRecords([$entry])
            ->mountTableAction('details', $entry)
            ->assertSee('Renamed Vendor');

        $this->assertFalse(auth()->user()->can('delete', $entry));
        $this->assertFalse(auth()->user()->can('update', $entry));
    }

    public function test_system_health_and_the_dashboard_render(): void
    {
        $this->actingAsVerifiedSuperAdmin();

        $this->get(SystemHealthPage::getUrl())->assertOk()->assertSee('Database')->assertSee('Scheduler');
        $this->get('/super-admin')->assertOk()->assertSee('Admins');
    }
}
