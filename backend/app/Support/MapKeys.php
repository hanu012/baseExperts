<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The two Google Maps keys, read from admin Settings → Maps first and from
 * .env (config/map.php) when no key has been saved there.
 *
 * Everything that needs a key goes through here, so the precedence rule
 * lives in one place.
 */
class MapKeys
{
    /**
     * Loads Google Maps JavaScript on the zone form. Rendered into the
     * admin page — must be referrer-restricted in Google Cloud.
     */
    public static function browserKey(): ?string
    {
        return static::pick(Setting::get('google_maps_browser_key'), config('map.google_browser_key'));
    }

    /**
     * Google Places, used server-side by PlaceLookupController only. Never
     * rendered into a page.
     */
    public static function placesKey(): ?string
    {
        return static::pick(Setting::get('google_maps_api_key'), config('map.google_api_key'));
    }

    private static function pick(mixed $fromSettings, mixed $fromEnv): ?string
    {
        foreach ([$fromSettings, $fromEnv] as $key) {
            if (is_string($key) && trim($key) !== '') {
                return trim($key);
            }
        }

        return null;
    }
}
