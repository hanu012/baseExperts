<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\Vendor;
use App\Services\AuthProvider;
use App\Services\Firebase\FirebaseAuthClient;
use App\Services\Payments\RazorpayClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The checks on Super Admin → System health. Each one is cheap, never
 * throws (a failing check is a red row, not an error page), and says in
 * plain words what is wrong and what to do.
 */
class SystemHealth
{
    public const SCHEDULER_HEARTBEAT = 'system_health.scheduler_last_run';

    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /**
     * @return array<int, array{label: string, status: string, detail: string}>
     */
    public function checks(): array
    {
        return array_map(
            fn (string $method) => rescue(
                fn () => $this->{$method}(),
                fn (\Throwable $e) => $this->row(ucfirst($method), self::FAIL, 'Check failed: '.$e->getMessage()),
                report: false,
            ),
            ['database', 'scheduler', 'queue', 'realtime', 'signIn', 'payments', 'mail', 'storage', 'php', 'debug'],
        );
    }

    private function database(): array
    {
        DB::select('select 1');

        return $this->row('Database', self::OK, 'Connected ('.DB::connection()->getDriverName().').');
    }

    /** The daily expiry job depends on cron calling `schedule:run` every minute. */
    private function scheduler(): array
    {
        $last = Cache::get(self::SCHEDULER_HEARTBEAT);

        if ($last === null) {
            return $this->row('Scheduler (daily jobs)', self::FAIL,
                'Never seen running. Add the cron entry: * * * * * php artisan schedule:run — without it subscriptions never expire and reminders never go out.');
        }

        $minutes = intdiv(now()->getTimestamp() - (int) $last, 60);

        return $minutes <= 5
            ? $this->row('Scheduler (daily jobs)', self::OK, "Running — last tick {$minutes} min ago.")
            : $this->row('Scheduler (daily jobs)', self::FAIL, "Last tick {$minutes} min ago — cron has stopped calling schedule:run.");
    }

    private function queue(): array
    {
        $driver = config('queue.default');
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

        if ($driver === 'database' && Schema::hasTable('jobs')) {
            $oldest = DB::table('jobs')->min('created_at');
            $waitingMinutes = $oldest !== null ? intdiv(now()->getTimestamp() - (int) $oldest, 60) : 0;

            if ($waitingMinutes > 10) {
                return $this->row('Queue worker', self::FAIL, "A job has waited {$waitingMinutes} min — the worker (php artisan queue:work) is not running. Failed jobs: {$failed}.");
            }
        }

        return $failed > 0
            ? $this->row('Queue worker', self::WARN, "Driver: {$driver}. {$failed} failed ".($failed === 1 ? 'job' : 'jobs').' — see php artisan queue:failed.')
            : $this->row('Queue worker', self::OK, "Driver: {$driver}. No failed jobs.");
    }

    /** Laravel Reverb — the apps' live updates (account approval push). */
    private function realtime(): array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return $this->row('Live updates (Reverb)', self::WARN, 'Off — the apps re-check on resume instead of updating live.');
        }

        $host = (string) config('broadcasting.connections.reverb.options.host', '127.0.0.1');
        $port = (int) config('broadcasting.connections.reverb.options.port', 8080);
        $socket = @fsockopen($host, $port, $errno, $error, 1.0);

        if ($socket === false) {
            return $this->row('Live updates (Reverb)', self::FAIL, "Not reachable at {$host}:{$port} — start it with php artisan reverb:start.");
        }

        fclose($socket);

        return $this->row('Live updates (Reverb)', self::OK, "Running at {$host}:{$port}.");
    }

    private function signIn(): array
    {
        $firebaseReady = app(FirebaseAuthClient::class)->isConfigured();

        if (AuthProvider::chosen() === AuthProvider::FIREBASE) {
            return $firebaseReady
                ? $this->row('Sign-in', self::OK, 'Firebase sign-in is on and set up.')
                : $this->row('Sign-in', self::FAIL, 'Firebase sign-in is chosen but not set up, so this server is checking passwords instead. Add FCM_* and the Web API key.');
        }

        return $this->row('Sign-in', self::OK, 'Checked by this server (Laravel).'.($firebaseReady ? ' Firebase is set up if you want to switch.' : ''));
    }

    private function payments(): array
    {
        if (! Setting::get('razorpay_payment_links_enabled', false)) {
            return $this->row('Online payments', self::WARN, 'Razorpay is off — online sales are recorded as paid without collecting money (placeholder).');
        }

        return app(RazorpayClient::class)->isConfigured()
            ? $this->row('Online payments', self::OK, 'Razorpay payment links are on and all three credentials are set.')
            : $this->row('Online payments', self::FAIL, 'Razorpay is on but credentials are incomplete — online sales are refused.');
    }

    private function mail(): array
    {
        $mailer = (string) config('mail.default');

        return in_array($mailer, ['log', 'array'], true)
            ? $this->row('Email', self::WARN, "Mailer is \"{$mailer}\" — emails are not delivered. Fine while Firebase sends password emails; otherwise set MAIL_* in .env.")
            : $this->row('Email', self::OK, "Sending with the \"{$mailer}\" mailer.");
    }

    private function storage(): array
    {
        $disk = Vendor::currentUploadDisk();
        $probe = '.health-check-'.uniqid();

        Storage::disk($disk)->put($probe, 'ok');
        Storage::disk($disk)->delete($probe);

        return $this->row('File storage', self::OK, "Uploads go to the \"{$disk}\" disk, and it is writable.");
    }

    private function php(): array
    {
        $missing = array_values(array_filter(['intl', 'pdo_mysql', 'openssl', 'mbstring', 'curl'], fn (string $ext) => ! extension_loaded($ext)));

        return $missing === []
            ? $this->row('PHP', self::OK, 'PHP '.PHP_VERSION.' with intl, pdo_mysql, openssl, mbstring and curl.')
            : $this->row('PHP', self::FAIL, 'PHP '.PHP_VERSION.' is missing: '.implode(', ', $missing).'. Enable them in php.ini.');
    }

    private function debug(): array
    {
        if (app()->environment('production') && config('app.debug')) {
            return $this->row('Debug mode', self::FAIL, 'APP_DEBUG is on in production — error pages show code and settings. Set APP_DEBUG=false.');
        }

        return $this->row('Debug mode', self::OK, 'Environment: '.app()->environment().', debug '.(config('app.debug') ? 'on' : 'off').'.');
    }

    /** @return array{label: string, status: string, detail: string} */
    private function row(string $label, string $status, string $detail): array
    {
        return ['label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
