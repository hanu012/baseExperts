<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to one or more roles, read from the users.role column.
 *
 *   Route::get('/admin/stats', ...)->middleware(['auth:sanctum', 'role:admin']);
 *   Route::get('/leads', ...)->middleware(['auth:sanctum', 'role:admin,salesman']);
 *
 * Always pair with auth:sanctum — this middleware authorises, it does not
 * authenticate.
 */
class RolesMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->deny($request, 'UNAUTHENTICATED', 'Unauthenticated.', 401);
        }

        if (! in_array($user->role->value, $roles, strict: true)) {
            return $this->deny($request, 'FORBIDDEN', 'This action is unauthorized.', 403);
        }

        return $next($request);
    }

    /**
     * API callers get the JSON envelope; everything else — the Filament admin
     * panel, in practice — gets a normal HTML error page. Without this the
     * panel would render a raw JSON body in the browser.
     */
    private function deny(Request $request, string $code, string $message, int $status): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return ApiResponse::error($code, $message, $status);
        }

        abort($status, $message);
    }
}
