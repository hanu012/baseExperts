<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuditLog;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Admin-editable settings (SPEC section 5.17), key-value with a declared
 * type so callers can read a typed value without re-parsing `value` (raw
 * text) themselves every time.
 *
 * Phase 3's salesman flow reads its business rules from here rather than
 * hardcoding them — see the settings migration for the full documented key
 * list.
 *
 * RecordsAuditLog matters more here than on most models: SPEC section
 * 5.14 calls out audit logging as "especially important since salesmen
 * can grant free subscriptions," and free_trial_max_days /
 * free_grants_per_salesman_month are exactly the governance-sensitive
 * values that note is about. Only fires on a real Eloquent update() per
 * row — the settings admin page (task 6.7) deliberately never does a
 * mass query-builder update for this reason.
 */
class Setting extends Model
{
    use RecordsAuditLog;

    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'label',
        'description',
        'is_editable',
    ];

    protected function casts(): array
    {
        return [
            'is_editable' => 'boolean',
        ];
    }

    /**
     * Reads a setting, cast per its declared `type`. Returns $default when
     * the key is missing entirely — this is the common path in a freshly
     * seeded or partially seeded environment, not an error.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        if ($setting === null || $setting->value === null) {
            return $default;
        }

        return match ($setting->type) {
            'integer' => (int) $setting->value,
            'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($setting->value, true),
            'encrypted' => static::decrypt($setting->value, $default),
            default => $setting->value,
        };
    }

    /**
     * Stores a secret: the row (and so its audit-log entries) only ever
     * holds the ciphertext. Null clears it.
     */
    public static function encrypt(?string $value): ?string
    {
        return filled($value) ? Crypt::encryptString($value) : null;
    }

    /**
     * A value encrypted under a previous APP_KEY cannot be read back; that
     * is treated as "not set" rather than taking the page down.
     */
    private static function decrypt(string $value, mixed $default): mixed
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $default;
        }
    }
}
