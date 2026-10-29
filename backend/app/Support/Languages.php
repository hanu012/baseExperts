<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The languages the apps ship translations for, and which of them the
 * admin has switched on (Settings → App → Enabled languages).
 *
 * English is always enabled: it is the fallback every translated field and
 * every app string resolves to when a translation is missing.
 */
class Languages
{
    public const ENGLISH = 'en';

    /** code => name in its own script */
    public const SUPPORTED = [
        'en' => 'English',
        'hi' => 'हिन्दी',
        'gu' => 'ગુજરાતી',
    ];

    /**
     * @return array<int, string>
     */
    public static function enabled(): array
    {
        $stored = Setting::get('enabled_languages', array_keys(self::SUPPORTED));

        $enabled = array_values(array_intersect(
            array_keys(self::SUPPORTED),
            array_merge([self::ENGLISH], is_array($stored) ? $stored : []),
        ));

        return $enabled === [] ? [self::ENGLISH] : $enabled;
    }

    /**
     * The value stored in a translated column, decoded to one language —
     * for query-builder reads, which bypass the model and get raw JSON.
     */
    public static function pick(?string $stored, ?string $locale = null): ?string
    {
        if ($stored === null) {
            return null;
        }

        $translations = json_decode($stored, true);

        if (! is_array($translations)) {
            return $stored;
        }

        $locale ??= app()->getLocale();

        return filled($translations[$locale] ?? null)
            ? $translations[$locale]
            : ($translations[self::ENGLISH] ?? (reset($translations) ?: null));
    }
}
