<?php

namespace App\Filament\Pages;

use App\Models\ApiErrorLog;
use App\Models\Setting;
use App\Services\AuthProvider;
use App\Services\Firebase\FirebaseAuthClient;
use App\Services\PasswordResetService;
use App\Services\Payments\RazorpayClient;
use App\Support\Languages;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Settings (SPEC section 5 item 17) — a single fixed-key edit page,
 * not a Resource: there is no create/delete, only 5 named values to
 * change. The first form-only custom Filament Page in this app
 * (LeadsAnalytics/CommissionPayouts are custom Pages too, but both
 * use HasTable; this one has no table at all, just HasForms).
 *
 * force_update_version and maintenance_mode are edited here but
 * DELIBERATELY NOT ENFORCED anywhere yet — grepped the whole repo
 * (backend + mobile/): no API middleware gate, no Flutter check of
 * either value at all. Confirmed by SettingController's and
 * SettingSeeder's own docblocks saying the same. This page makes
 * them admin-editable, not functional — flagged in the form's own
 * helper text so an admin using the panel isn't misled, not just in
 * this comment.
 */
class SettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $navigationGroup = 'System';

    protected static string $view = 'filament.pages.settings-page';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * The 5 keys SPEC section 5.17 names. Fixed, not dynamically
     * discovered from the settings table — a new key needs a
     * deliberate addition here, not an automatic appearance on this
     * page.
     *
     * @var array<int, string>
     */
    //
    // Split across two pages: the business values here, in /admin, and the
    // technical ones (keys, sign-in, error log) on SystemSettingsPage in
    // /super-admin, which extends this class and swaps the lists below.
    // Each page saves only its own keys, so neither can blank the other's.
    private const BUSINESS_KEYS = [
        // free_grants_per_salesman_month and grace_period_days are left off
        // the page on purpose (client request). They are still enforced at
        // their stored values; leaving them out of this list is what keeps
        // Save from overwriting them with nulls.
        'free_trial_max_days',
        'force_update_version',
        'maintenance_mode',
        'enabled_languages',
        'call_tracking_enabled',
    ];

    protected const TECHNICAL_KEYS = [
        'razorpay_payment_links_enabled',
        'google_maps_browser_key',
        'razorpay_key_id',
        'auth_provider',
        'password_reset_method',
        'firebase_web_api_key',
        'api_error_log_enabled',
        'api_error_log_level',
        // The secrets are deliberately NOT here — see SECRETS.
    ];

    /**
     * Write-only secrets: never filled back into the form (Livewire state is
     * serialised into the page, so a filled field would put the secret in
     * the HTML), stored encrypted, and saved by saveSecret(). Each has a
     * `clear_<key>` switch to remove it.
     *
     * @var array<int, string>
     */
    protected const SECRETS = [
        'google_maps_api_key',
        'razorpay_key_secret',
        'razorpay_webhook_secret',
    ];

    /** @return array<int, string> the plain keys this page shows and saves */
    protected static function keys(): array
    {
        return self::BUSINESS_KEYS;
    }

    /** @return array<int, string> the write-only secrets this page shows and saves */
    protected static function secrets(): array
    {
        return [];
    }

    /** @return array<int, string> which sections of the form this page shows */
    protected static function sections(): array
    {
        return ['subscriptions', 'app'];
    }

    protected static function shows(string $section): bool
    {
        return in_array($section, static::sections(), true);
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('viewAny', Setting::class) ?? false;
    }

    public function mount(): void
    {
        // Every value is filled, but only this page's sections render and
        // only static::keys() are saved.
        $this->form->fill([
            'free_trial_max_days' => Setting::get('free_trial_max_days'),
            'force_update_version' => Setting::get('force_update_version'),
            'maintenance_mode' => Setting::get('maintenance_mode', false),
            'enabled_languages' => Languages::enabled(),
            'razorpay_payment_links_enabled' => Setting::get('razorpay_payment_links_enabled', false),
            'call_tracking_enabled' => Setting::get('call_tracking_enabled', true),
            'google_maps_browser_key' => Setting::get('google_maps_browser_key'),
            'razorpay_key_id' => Setting::get('razorpay_key_id'),
            'password_reset_method' => in_array(Setting::get('password_reset_method'), PasswordResetService::METHODS, true)
                ? Setting::get('password_reset_method')
                : PasswordResetService::LARAVEL,
            'auth_provider' => AuthProvider::chosen(),
            'firebase_web_api_key' => Setting::get('firebase_web_api_key'),
            'api_error_log_enabled' => (bool) Setting::get('api_error_log_enabled', false),
            'api_error_log_level' => Setting::get('api_error_log_level', 'server'),
            ...$this->emptySecrets(),
        ]);
    }

    /** @return array<string, mixed> every secret field blank, every clear switch off */
    private function emptySecrets(): array
    {
        $state = [];

        foreach (static::secrets() as $key) {
            $state[$key] = null;
            $state['clear_'.$key] = false;
        }

        return $state;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Subscriptions')
                    ->visible(static::shows('subscriptions'))
                    ->description('Enforced when a salesman grants a free trial.')
                    ->schema([
                        TextInput::make('free_trial_max_days')
                            ->label('Maximum free trial length (days)')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                    ])
                    ->columns(3),

                Section::make('Payments')
                    ->visible(static::shows('payments'))
                    ->description('How "Online" payments are taken in the salesman and vendor apps. Cash and free trials are not affected.')
                    ->schema([
                        Toggle::make('razorpay_payment_links_enabled')
                            ->label('Online payments via Razorpay')
                            ->columnSpanFull()
                            ->helperText(fn (): string => 'On: the vendor gets a Razorpay payment link by SMS, and the plan or add-on activates when it is paid. '
                                .'Off: online payments are recorded as paid immediately (placeholder — no money is collected). '
                                .(app(RazorpayClient::class)->isConfigured()
                                    ? 'All three Razorpay credentials are set.'
                                    : '⚠ Razorpay credentials are incomplete — with this on, online sales will be refused until all three below are set.')),

                        TextInput::make('razorpay_key_id')
                            ->label('Key id')
                            ->maxLength(255)
                            ->placeholder('rzp_test_… or rzp_live_…')
                            ->rule('nullable')
                            ->regex('/^rzp_(test|live)_[A-Za-z0-9]+$/')
                            ->validationMessages(['regex' => 'A Razorpay key id starts with rzp_test_ or rzp_live_.'])
                            ->helperText(fn (): string => 'Razorpay Dashboard → Account & Settings → API keys. Not secret. '
                                .$this->keySource('razorpay_key_id', 'services.razorpay.key_id', 'RAZORPAY_KEY_ID')),

                        ...$this->secretFields(
                            'razorpay_key_secret',
                            'Key secret',
                            'Shown once by Razorpay when the key is generated. ',
                            'services.razorpay.key_secret',
                            'RAZORPAY_KEY_SECRET',
                        ),

                        ...$this->secretFields(
                            'razorpay_webhook_secret',
                            'Webhook secret',
                            'The secret you typed when creating the webhook (Razorpay Dashboard → Webhooks) — not the key secret. '
                                .'Webhook URL: '.url('/api/webhooks/razorpay').' with events payment_link.paid, payment_link.expired and payment_link.cancelled. ',
                            'services.razorpay.webhook_secret',
                            'RAZORPAY_WEBHOOK_SECRET',
                        ),
                    ])
                    ->columns(2),

                Section::make('Sign-in & password reset')
                    ->visible(static::shows('sign-in'))
                    ->description('"Forgot password?" in all three apps emails the user a link to set a new password — the only way a password is reset; admins never set one. Every account is always kept in this server\'s Users list, whichever is chosen.')
                    ->schema([
                        Select::make('auth_provider')
                            ->label('App sign-in checked by')
                            ->options([
                                AuthProvider::LARAVEL => 'This server (Laravel)',
                                AuthProvider::FIREBASE => 'Firebase',
                            ])
                            ->required()
                            ->selectablePlaceholder(false)
                            ->live()
                            ->columnSpanFull()
                            ->helperText(fn (Get $get): string => $this->authProviderHelp((string) $get('auth_provider'))),

                        // Firebase sign-in always uses Firebase's email, so
                        // the choice only exists with Laravel sign-in.
                        // Hidden (not saved) otherwise; the stored value is
                        // kept for when sign-in goes back to Laravel.
                        Select::make('password_reset_method')
                            ->label('Password reset email sent by')
                            ->options([
                                PasswordResetService::LARAVEL => 'This server (Laravel)',
                                PasswordResetService::FIREBASE => 'Firebase',
                            ])
                            ->required()
                            ->selectablePlaceholder(false)
                            ->live()
                            ->visible(fn (Get $get): bool => $get('auth_provider') !== AuthProvider::FIREBASE)
                            ->columnSpanFull()
                            ->helperText(fn (Get $get): string => $this->passwordResetHelp((string) $get('password_reset_method'))),

                        TextInput::make('firebase_web_api_key')
                            ->label('Firebase Web API key')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('auth_provider') === AuthProvider::FIREBASE
                                || $get('password_reset_method') === PasswordResetService::FIREBASE)
                            ->columnSpanFull()
                            ->helperText(fn (): string => 'Firebase Console → Project settings → General → Web API key. Public, not a secret. '
                                .'Also enable Authentication → Sign-in method → Email/Password. '
                                .$this->keySource('firebase_web_api_key', 'services.firebase.web_api_key', 'FIREBASE_WEB_API_KEY')),
                    ])
                    ->columns(2),

                Section::make('Error log')
                    ->visible(static::shows('error-log'))
                    ->description('Records failed API calls from the apps so they can be investigated in the panel.')
                    ->schema([
                        Toggle::make('api_error_log_enabled')
                            ->label('Record API errors')
                            ->live()
                            ->helperText(fn (): string => 'On: failed calls are saved and System → API error log appears in the menu. '
                                .'Off: nothing is recorded and that page is hidden. Request data is stored with passwords, tokens and secrets blanked; '
                                .'entries are deleted after '.ApiErrorLog::RETENTION_DAYS.' days. '
                                .'Viewing needs the "View logs" permission.'),

                        Select::make('api_error_log_level')
                            ->label('What to record')
                            ->options([
                                'server' => 'Server errors only (5xx) — bugs and crashes',
                                'all' => 'All failed calls (4xx and 5xx) — also wrong input, sign-in refusals, not found',
                            ])
                            ->selectablePlaceholder(false)
                            ->required()
                            ->visible(fn (Get $get): bool => (bool) $get('api_error_log_enabled'))
                            ->helperText('A reply that says "success": false is always recorded, whatever its status — the apps show it as an error. '
                                .'"All" is useful while testing the apps; it records a lot more on a live system.'),
                    ])
                    ->columns(2),

                Section::make('Maps')
                    ->visible(static::shows('maps'))
                    ->description('Google Maps keys for the admin zone map. A key saved here takes priority over the one in .env.')
                    ->schema([
                        TextInput::make('google_maps_browser_key')
                            ->label('Google Maps browser key')
                            ->maxLength(255)
                            ->password()
                            ->revealable()
                            ->helperText(fn (): string => 'Loads the map on Zones → Zone Configuration. It is visible in the page, so in Google Cloud restrict it to '
                                .'HTTP referrers (your admin domain) and to the Maps JavaScript API only. '
                                .$this->keySource('google_maps_browser_key', 'map.google_browser_key', 'GOOGLE_MAPS_BROWSER_KEY')),

                        ...$this->secretFields(
                            'google_maps_api_key',
                            'Google Places server key',
                            'Powers the place search above the zone map. Enable "Places API (New)" for it in Google Cloud. ',
                            'map.google_api_key',
                            'GOOGLE_MAPS_API_KEY',
                        ),
                    ])
                    ->columns(2),

                Section::make('App')
                    ->visible(static::shows('app'))
                    ->description('Minimum app version and maintenance mode are not enforced anywhere yet — editable here ahead of that work. Enabled languages is live.')
                    ->schema([
                        TextInput::make('force_update_version')
                            ->label('Minimum app version')
                            ->required()
                            ->maxLength(255)
                            ->rule('regex:/^\d+\.\d+\.\d+$/')
                            ->helperText('x.y.z format. Not enforced by any app yet.'),

                        Toggle::make('call_tracking_enabled')
                            ->label('Call tracking in apps')
                            ->helperText('On: vendors see Recent calls on their home screen, customers see Profile → My calls, and each call is timed (approximately — includes ringing). Off: those are hidden; calls and the admin Leads page are unaffected. Apps pick up a change on their next launch.'),

                        Toggle::make('maintenance_mode')
                            ->label('Maintenance mode')
                            ->helperText('Not enforced by any app yet.'),

                        CheckboxList::make('enabled_languages')
                            ->label('Enabled languages')
                            ->options(Languages::SUPPORTED)
                            // English is the fallback for every missing
                            // translation, so it cannot be switched off.
                            ->disableOptionWhen(fn (string $value): bool => $value === Languages::ENGLISH)
                            ->columns(3)
                            ->helperText('Offered in each app under Profile → Language. English is always on. Someone using a language you switch off is moved back to English.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(Auth::user()?->can('update', Setting::class), 403);

        $data = $this->form->getState();

        $settings = Setting::query()->whereIn('key', static::keys())->get()->keyBy('key');

        foreach (static::keys() as $key) {
            $setting = $settings->get($key);

            // Missing row (never seeded) or explicitly excluded from
            // the admin UI (is_editable = false) — skip rather than
            // create one or silently overwrite a deploy-only value.
            if ($setting === null || ! $setting->is_editable) {
                continue;
            }

            // A field hidden on the form (the Firebase key while another
            // reset method is chosen) is not in the submitted data at all.
            // Leave its stored value alone rather than wipe it.
            if (! array_key_exists($key, $data)) {
                continue;
            }

            // A real Eloquent update() per row, not a mass
            // query-builder update — RecordsAuditLog only fires on
            // the former, and settings changes are exactly the kind
            // of governance-sensitive edit SPEC section 5.14 wants
            // logged.
            $setting->update([
                'value' => $this->stringify($data[$key] ?? null, $setting->type),
            ]);
        }

        foreach (static::secrets() as $key) {
            $this->saveSecret($key, $data[$key] ?? null, (bool) ($data['clear_'.$key] ?? false));
        }

        // Empty the secret fields again so a typed secret does not linger in
        // the page's Livewire state.
        $this->data = array_merge($this->data ?? [], $this->emptySecrets());

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    /**
     * Write-only secret: blank keeps what is stored, a new value replaces
     * it (encrypted), and the clear switch removes it.
     */
    private function saveSecret(string $key, ?string $value, bool $clear): void
    {
        $setting = Setting::query()->where('key', $key)->first();

        if ($setting === null || ! $setting->is_editable) {
            return;
        }

        if ($clear) {
            $setting->update(['value' => null]);

            return;
        }

        if (filled($value)) {
            $setting->update(['value' => Setting::encrypt(trim($value))]);
        }
    }

    /** What the chosen sign-in provider does, and whether Firebase is ready. */
    private function authProviderHelp(string $provider): string
    {
        if ($provider !== AuthProvider::FIREBASE) {
            return 'Passwords are checked by this server. Accounts created by an admin or sold by a salesman get a temporary password to share.';
        }

        return 'Passwords are checked by Firebase; every account is still kept here. Existing users move to Firebase on their next sign-in, '
            .'with the password they already have. "Forgot password?" uses Firebase\'s email, and accounts created by an admin or sold by a '
            .'salesman are emailed a link to set their own password instead of a temporary one. If Firebase cannot be reached, sign-in falls '
            .'back to this server. '
            .(app(FirebaseAuthClient::class)->isConfigured()
                ? 'Firebase is set up.'
                : '⚠ Not set up yet (FCM_* in .env plus the Web API key below) — until it is, sign-in stays with this server.');
    }

    /** What the chosen reset method needs, and whether it is there. */
    private function passwordResetHelp(string $method): string
    {
        $mailStatus = config('mail.default') === 'log'
            ? '⚠ MAIL_MAILER is "log": emails are only written to the server log, nobody receives them.'
            : 'Currently sending with the "'.config('mail.default').'" mailer.';

        return match ($method) {
            PasswordResetService::FIREBASE => 'Firebase sends the email (free); its link opens a page on this server where the user chooses a new password. '
                .'Uses the Firebase project from FCM_* in .env plus the Web API key below. '
                .(app(FirebaseAuthClient::class)->isConfigured()
                    ? 'Firebase is set up.'
                    : '⚠ Not set up yet — until it is, this server sends the email instead. '.$mailStatus),
            default => 'This server emails a link to a page where the user chooses a new password. '
                .'Needs outgoing mail set up in .env (MAIL_MAILER, MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS). '
                .$mailStatus,
        };
    }

    /**
     * A write-only secret input plus its "remove" switch.
     *
     * @return array<int, \Filament\Forms\Components\Component>
     */
    private function secretFields(string $key, string $label, string $help, string $configKey, string $envName): array
    {
        return [
            TextInput::make($key)
                ->label($label)
                ->maxLength(255)
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->placeholder(fn (): string => filled(Setting::get($key))
                    ? '•••••••• saved — leave blank to keep'
                    : 'Paste it here')
                ->helperText(fn (): string => $help
                    .'Stored encrypted and never shown again after saving. '
                    .$this->keySource($key, $configKey, $envName)),

            Toggle::make('clear_'.$key)
                ->label('Remove the saved '.strtolower($label))
                ->visible(fn (): bool => filled(Setting::get($key)))
                ->helperText("Falls back to {$envName} in .env, if set."),
        ];
    }

    /** "Saved here" / "from .env" / "not set", for a credential's helper text. */
    private function keySource(string $settingKey, string $configKey, string $envName): string
    {
        if (filled(Setting::get($settingKey))) {
            return 'Currently: saved in Settings.';
        }

        return filled(config($configKey))
            ? "Currently: using {$envName} from .env."
            : '⚠ Not set.';
    }

    private function stringify(mixed $value, string $type): ?string
    {
        if ($type === 'boolean') {
            return $value ? 'true' : 'false';
        }

        if ($type === 'json') {
            return json_encode(array_values((array) $value));
        }

        return $value === null ? null : (string) $value;
    }
}
