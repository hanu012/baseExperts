<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Setting;
use App\Services\PasswordResetService;
use App\Support\Languages;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    /**
     * A whitelisted subset of admin-editable settings (SPEC section 5.17),
     * for screens that need a live value rather than a hardcoded one — e.g.
     * the free-trial duration picker capped by free_trial_max_days.
     *
     * DELIBERATELY NOT the whole settings table: some rows are internal
     * (force_update_version, maintenance_mode gating) or simply not needed
     * by any client yet. Add a key here only when a screen actually reads
     * it, same reasoning as every other master-data endpoint in this file's
     * siblings.
     *
     * Public: no token required, matching /api/plans, /api/categories,
     * /api/zones.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'free_trial_max_days' => Setting::get('free_trial_max_days', 15),
            // What Profile → Language offers. Always includes 'en'.
            'enabled_languages' => Languages::enabled(),
            // Admin switch: vendor Recent calls, customer My calls, and the
            // customer app timing each call.
            'call_tracking_enabled' => (bool) Setting::get('call_tracking_enabled', true),
            // How "Forgot password?" works right now (admin | laravel |
            // firebase), so the screen can say "we'll email you a link" or
            // "our team will contact you" before the user submits.
            'password_reset_method' => app(PasswordResetService::class)->method(),
            'realtime' => $this->realtime(),
        ]);
    }

    /**
     * Where the apps open their live socket (Laravel Reverb), or null when
     * live events are off — the apps then just re-check on resume. The key
     * is Reverb's PUBLIC app key (Pusher protocol); the secret never leaves
     * the server.
     *
     * @return array<string, mixed>|null
     */
    private function realtime(): ?array
    {
        $connection = config('broadcasting.connections.reverb');

        if (config('broadcasting.default') !== 'reverb' || blank($connection['key'] ?? null)) {
            return null;
        }

        return [
            'driver' => 'reverb',
            'key' => $connection['key'],
            'host' => $connection['public']['host'],
            'port' => $connection['public']['port'],
            'scheme' => $connection['public']['scheme'],
        ];
    }
}
