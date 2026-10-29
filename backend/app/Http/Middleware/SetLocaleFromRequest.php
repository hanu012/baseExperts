<?php

namespace App\Http\Middleware;

use App\Support\Languages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves translated master data (category, plan, banner... names) in the
 * language the app sends as Accept-Language. Anything not enabled by the
 * admin, or absent, falls back to English.
 */
class SetLocaleFromRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = Languages::enabled();

        // getPreferredLanguage() honours q-values and region tags
        // ("gu-IN") and returns the first entry, English, on no match.
        app()->setLocale($request->getPreferredLanguage($enabled) ?? Languages::ENGLISH);

        return $next($request);
    }
}
