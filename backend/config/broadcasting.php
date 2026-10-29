<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | "reverb" pushes live events to the apps through Laravel Reverb
    | (`php artisan reverb:start`, see CLAUDE.md → Realtime). "log" or
    | "null" switch live events off; the apps then fall back to checking
    | when they come back to the foreground.
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                // Where THIS server reaches Reverb — usually itself.
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [],

            // Where the APPS reach Reverb, sent to them by GET /api/settings.
            // Behind a proxy in production this is the public host on 443
            // with wss, not the 127.0.0.1:8080 above.
            'public' => [
                'host' => env('REVERB_PUBLIC_HOST', env('REVERB_HOST', '127.0.0.1')),
                'port' => (int) env('REVERB_PUBLIC_PORT', env('REVERB_PORT', 8080)),
                'scheme' => env('REVERB_PUBLIC_SCHEME', env('REVERB_SCHEME', 'http')),
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
