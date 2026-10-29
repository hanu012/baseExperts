<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Maps (browser key)
    |--------------------------------------------------------------------------
    |
    | The admin zone map is Google Maps JavaScript, loaded from
    | maps.googleapis.com — the one third-party script the admin panel is
    | allowed (see CLAUDE.md). Google does not permit self-hosting it.
    |
    | This key is rendered into the admin page, so it is a SEPARATE key from
    | google_api_key below and must be locked down in Google Cloud:
    |   - Application restriction: HTTP referrers, the admin domain(s) only
    |     (e.g. https://admin.example.com/*, http://127.0.0.1:8000/*).
    |   - API restriction: "Maps JavaScript API" only.
    | Restricted that way, a copied key is useless anywhere else.
    |
    | Both keys in this file can also be set in the admin panel under
    | Settings → Maps; a key saved there wins over .env (App\Support\MapKeys).
    | With neither set, the map is replaced by a notice saying so.
    |
    */

    'google_browser_key' => env('GOOGLE_MAPS_BROWSER_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Default view
    |--------------------------------------------------------------------------
    |
    | Where the map opens when drawing a new zone. Ahmedabad, matching the
    | worked examples in SPEC sections 3.4 and 5.3.
    |
    */

    'default_latitude' => (float) env('MAP_DEFAULT_LAT', 23.0225),
    'default_longitude' => (float) env('MAP_DEFAULT_LNG', 72.5714),
    'default_zoom' => (int) env('MAP_DEFAULT_ZOOM', 11),

    /*
    |--------------------------------------------------------------------------
    | Place search
    |--------------------------------------------------------------------------
    |
    | Google Places backs the zone map's search box, so an admin can type
    | an address and jump to it. Google is used rather than OpenStreetMap's
    | Nominatim because it knows individual buildings and apartment blocks
    | that OSM does not — the difference between finding a named apartment
    | and having to settle for the suburb around it. Nominatim also matches
    | a pasted address literally, returning nothing at all when one
    | component is unknown to it.
    |
    | The key is used SERVER-SIDE ONLY, proxied through
    | PlaceLookupController. It is never rendered into the admin page: a
    | Places key is billable, and one sitting in HTML is one anybody can
    | lift and spend. That proxy is admin-only for the same reason.
    |
    | Leave empty and the search box is hidden — the map still draws and
    | the polygon tools still work, there is simply nothing to search with.
    |
    */

    'google_api_key' => env('GOOGLE_MAPS_API_KEY'),

    /*
    | Biases results towards one country, as an ISO 3166-1 alpha-2 code.
    | Empty searches worldwide.
    */

    'geocode_country' => env('MAP_GEOCODE_COUNTRY', 'in'),

];
