<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Pages\SettingsPage;
use App\Filament\SuperAdmin\Pages\SystemSettingsPage;
use Filament\Facades\Filament;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Languages;
use App\Services\Payments\RazorpayClient;
use App\Support\MapKeys;
use App\Support\RazorpayCredentials;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings (SPEC section 5 item 17) — the missing write side of
 * Setting::get() (task 3.4).
 */
class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingSeeder::class);

        $this->admin = User::factory()->role(UserRole::Admin)->create();
        $this->actingAs($this->admin);
    }

    /** Super Admin → System settings, the technical half (keys, sign-in, error log). */
    private function systemSettings(): \Livewire\Features\SupportTesting\Testable
    {
        Filament::setCurrentPanel(Filament::getPanel('super-admin'));

        return Livewire::test(SystemSettingsPage::class);
    }

    /** The split: business values here, keys and sign-in in /super-admin. */
    public function test_the_admin_page_shows_only_business_settings(): void
    {
        Livewire::test(SettingsPage::class)
            ->assertFormFieldIsVisible('free_trial_max_days')
            ->assertFormFieldIsVisible('enabled_languages')
            ->assertFormFieldIsHidden('razorpay_key_id')
            ->assertFormFieldIsHidden('google_maps_browser_key')
            ->assertFormFieldIsHidden('auth_provider')
            ->assertFormFieldIsHidden('api_error_log_enabled');

        $this->systemSettings()
            ->assertFormFieldIsVisible('razorpay_key_id')
            ->assertFormFieldIsVisible('auth_provider')
            ->assertFormFieldIsHidden('free_trial_max_days');
    }

    /** Saving one half never blanks the other half's values. */
    public function test_each_page_saves_only_its_own_keys(): void
    {
        Setting::where('key', 'razorpay_key_id')->update(['value' => 'rzp_test_keepme']);

        Livewire::test(SettingsPage::class)
            ->fillForm(['free_trial_max_days' => 22])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('rzp_test_keepme', Setting::get('razorpay_key_id'));

        $this->systemSettings()
            ->fillForm(['razorpay_key_id' => 'rzp_test_changed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(22, Setting::get('free_trial_max_days'));
        $this->assertSame('rzp_test_changed', Setting::get('razorpay_key_id'));
    }

    public function test_a_sub_admin_with_settings_permissions_cannot_open_system_settings(): void
    {
        $this->actingAs(User::factory()->subAdmin([
            Permission::SettingsViewAny->value,
            Permission::SettingsUpdate->value,
        ])->create());

        $this->assertFalse(SystemSettingsPage::canAccess());
        $this->get('/super-admin/system-settings')->assertForbidden();
    }

    public function test_the_page_renders_with_seeded_values_prefilled(): void
    {
        Livewire::test(SettingsPage::class)
            ->assertFormSet([
                'free_trial_max_days' => 15,
                'force_update_version' => '1.0.0',
                'maintenance_mode' => false,
            ]);
    }

    public function test_saving_updates_every_key_on_the_page_with_a_correct_type_round_trip(): void
    {
        Livewire::test(SettingsPage::class)
            ->fillForm([
                'free_trial_max_days' => 20,
                'force_update_version' => '2.1.0',
                'maintenance_mode' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(20, Setting::get('free_trial_max_days'));
        $this->assertSame('2.1.0', Setting::get('force_update_version'));
        $this->assertTrue(Setting::get('maintenance_mode'));
    }

    /**
     * Free grants per month and the grace period are off the page but
     * still enforced. Saving must leave them alone rather than null them.
     */
    public function test_saving_leaves_the_settings_not_on_the_page_untouched(): void
    {
        Livewire::test(SettingsPage::class)
            ->assertFormFieldDoesNotExist('free_grants_per_salesman_month')
            ->assertFormFieldDoesNotExist('grace_period_days')
            ->fillForm(['free_trial_max_days' => 20])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(10, Setting::get('free_grants_per_salesman_month'));
        $this->assertSame(7, Setting::get('grace_period_days'));
    }

    public function test_enabled_languages_are_prefilled_and_saved(): void
    {
        Livewire::test(SettingsPage::class)
            ->assertFormSet(['enabled_languages' => ['en', 'hi', 'gu']])
            ->fillForm(['enabled_languages' => ['en', 'gu']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['en', 'gu'], Setting::get('enabled_languages'));
    }

    /**
     * English is disabled in the UI, but a crafted submission can still
     * leave it out — it comes back regardless, as the fallback language.
     */
    public function test_english_cannot_be_switched_off(): void
    {
        Livewire::test(SettingsPage::class)
            ->fillForm(['enabled_languages' => ['hi']])
            ->call('save');

        $this->assertSame(['en', 'hi'], Languages::enabled());
    }

    public function test_force_update_version_rejects_a_malformed_version_string(): void
    {
        Livewire::test(SettingsPage::class)
            ->fillForm(['force_update_version' => 'not-a-version'])
            ->call('save')
            ->assertHasFormErrors(['force_update_version']);

        $this->assertSame('1.0.0', Setting::get('force_update_version'));
    }

    public function test_saving_writes_a_real_audit_log_entry_for_a_changed_key(): void
    {
        $setting = Setting::where('key', 'free_trial_max_days')->sole();

        // Seeding itself (RecordsAuditLog now sits on Setting) already
        // wrote one 'created' entry per row — this asserts no 'updated'
        // entry exists yet, not that the table is empty.
        $this->assertSame(0, AuditLog::where('auditable_type', $setting->getMorphClass())
            ->where('auditable_id', $setting->id)
            ->where('action', 'updated')
            ->count());

        Livewire::test(SettingsPage::class)
            ->fillForm(['free_trial_max_days' => 25])
            ->call('save');

        $entry = AuditLog::where('auditable_type', $setting->getMorphClass())
            ->where('auditable_id', $setting->id)
            ->where('action', 'updated')
            ->sole();

        $this->assertSame($this->admin->id, $entry->user_id);
        $this->assertSame('25', $entry->new_values['value']);
    }

    public function test_saving_with_nothing_changed_writes_no_new_audit_log_entries(): void
    {
        $baseline = AuditLog::count();

        Livewire::test(SettingsPage::class)->call('save');

        $this->assertSame($baseline, AuditLog::count());
    }

    // ── Google Maps keys ─────────────────────────────────────────────────

    public function test_the_browser_key_is_saved_and_beats_the_env_value(): void
    {
        config()->set('map.google_browser_key', 'env-browser-key');

        $this->assertSame('env-browser-key', MapKeys::browserKey());

        $this->systemSettings()
            ->fillForm(['google_maps_browser_key' => 'settings-browser-key'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('settings-browser-key', MapKeys::browserKey());
    }

    public function test_the_places_key_is_stored_encrypted_and_never_rendered(): void
    {
        $component = $this->systemSettings()
            ->fillForm(['google_maps_api_key' => 'secret-places-key'])
            ->call('save')
            ->assertHasNoFormErrors();

        $raw = Setting::where('key', 'google_maps_api_key')->value('value');

        $this->assertNotSame('secret-places-key', $raw);
        $this->assertSame('secret-places-key', MapKeys::placesKey());

        // Not left in the form after saving, and not shown on a fresh load.
        $component->assertFormSet(['google_maps_api_key' => null]);
        $this->assertStringNotContainsString('secret-places-key', $this->systemSettings()->html());

        // Nor in the audit trail.
        $this->assertStringNotContainsString('secret-places-key', AuditLog::query()->get()->toJson());
    }

    public function test_a_blank_places_key_keeps_the_saved_one_and_clear_removes_it(): void
    {
        $this->systemSettings()
            ->fillForm(['google_maps_api_key' => 'secret-places-key'])
            ->call('save');

        $this->systemSettings()
            ->fillForm(['google_maps_api_key' => null, 'google_maps_browser_key' => 'another-browser-key'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('secret-places-key', MapKeys::placesKey());

        config()->set('map.google_api_key', null);

        $this->systemSettings()
            ->fillForm(['clear_google_maps_api_key' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull(MapKeys::placesKey());
    }

    // ── Razorpay credentials ─────────────────────────────────────────────

    public function test_razorpay_credentials_saved_here_are_used_ahead_of_env(): void
    {
        config()->set('services.razorpay.key_id', 'rzp_test_fromenv');
        config()->set('services.razorpay.key_secret', null);
        config()->set('services.razorpay.webhook_secret', null);

        $this->assertFalse(app(RazorpayClient::class)->isConfigured());

        $this->systemSettings()
            ->fillForm([
                'razorpay_key_id' => 'rzp_test_fromsettings',
                'razorpay_key_secret' => 'key-secret-123',
                'razorpay_webhook_secret' => 'webhook-secret-456',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('rzp_test_fromsettings', RazorpayCredentials::keyId());
        $this->assertSame('key-secret-123', RazorpayCredentials::keySecret());
        $this->assertSame('webhook-secret-456', RazorpayCredentials::webhookSecret());
        $this->assertTrue(app(RazorpayClient::class)->isConfigured());
    }

    public function test_razorpay_secrets_are_encrypted_and_never_rendered(): void
    {
        $this->systemSettings()
            ->fillForm([
                'razorpay_key_secret' => 'key-secret-123',
                'razorpay_webhook_secret' => 'webhook-secret-456',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertFormSet(['razorpay_key_secret' => null, 'razorpay_webhook_secret' => null]);

        foreach (['razorpay_key_secret' => 'key-secret-123', 'razorpay_webhook_secret' => 'webhook-secret-456'] as $key => $plain) {
            $this->assertNotSame($plain, Setting::where('key', $key)->value('value'));
        }

        $html = $this->systemSettings()->html();
        $this->assertStringNotContainsString('key-secret-123', $html);
        $this->assertStringNotContainsString('webhook-secret-456', $html);
        $this->assertStringNotContainsString('key-secret-123', AuditLog::query()->get()->toJson());
    }

    public function test_a_webhook_signed_with_the_saved_secret_is_accepted(): void
    {
        config()->set('services.razorpay.webhook_secret', null);

        $this->systemSettings()
            ->fillForm(['razorpay_webhook_secret' => 'webhook-secret-456'])
            ->call('save');

        $body = '{"event":"payment.captured"}';

        $this->assertTrue(app(RazorpayClient::class)->verifyWebhookSignature(
            $body,
            hash_hmac('sha256', $body, 'webhook-secret-456'),
        ));
    }

    public function test_a_malformed_key_id_is_rejected(): void
    {
        $this->systemSettings()
            ->fillForm(['razorpay_key_id' => 'not-a-key'])
            ->call('save')
            ->assertHasFormErrors(['razorpay_key_id']);
    }

    // ── Permission gate ──────────────────────────────────────────────────

    public function test_a_sub_admin_without_the_settings_permission_cannot_access_the_page(): void
    {
        $subAdmin = User::factory()->role(UserRole::Admin)->create(['permissions' => []]);
        $this->actingAs($subAdmin);

        $this->get(SettingsPage::getUrl())->assertForbidden();
    }

    public function test_a_sub_admin_with_view_only_cannot_save(): void
    {
        $subAdmin = User::factory()->role(UserRole::Admin)->create([
            'permissions' => [Permission::SettingsViewAny->value],
        ]);
        $this->actingAs($subAdmin);

        // Livewire absorbs the abort_unless(403) into its own AJAX-style
        // response rather than letting it propagate to the test as a
        // catchable exception — so the real assertion is the one that
        // matters operationally: the value never actually moved.
        Livewire::test(SettingsPage::class)
            ->fillForm(['free_trial_max_days' => 30])
            ->call('save');

        $this->assertSame(15, Setting::get('free_trial_max_days'));
    }

    public function test_a_sub_admin_with_the_update_permission_can_save(): void
    {
        $subAdmin = User::factory()->role(UserRole::Admin)->create([
            'permissions' => [
                Permission::SettingsViewAny->value,
                Permission::SettingsUpdate->value,
            ],
        ]);
        $this->actingAs($subAdmin);

        Livewire::test(SettingsPage::class)
            ->fillForm(['free_trial_max_days' => 30])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(30, Setting::get('free_trial_max_days'));
    }
}
