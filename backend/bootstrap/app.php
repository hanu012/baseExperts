<?php

use App\Http\Middleware\LogApiErrors;
use App\Http\Middleware\RequireApprovedAccount;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\RolesMiddleware;
use App\Http\Middleware\SetLocaleFromRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // POST /api/broadcasting/auth — lets a signed-in app subscribe to its
    // own private channel (routes/channels.php). In the api group, so it
    // takes a Sanctum token and answers in JSON; RequireApprovedAccount
    // allowlists it, because the verification screen is exactly who listens.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RolesMiddleware::class,
        ]);

        // Both are appended to the whole api group rather than named
        // per-route: a gate that only applies to routes someone remembered
        // to tag is not enforcement. Each middleware carries its own
        // allowlist of the routes that must stay reachable (logout always
        // among them).
        //
        // Order matters. Password change runs first because a user who has
        // both problems should be sent to the change-password screen, not
        // left staring at a pending notice they cannot act on.
        $middleware->api(append: [
            RequirePasswordChange::class,
            RequireApprovedAccount::class,
        ]);

        // First, so even an early refusal is formed in the request's locale.
        $middleware->api(prepend: [
            SetLocaleFromRequest::class,
        ]);

        // Global rather than api-group, so it also sees an api/ 404 that
        // matches no route. It only acts on api/* failures, and only while
        // Settings → Error log is on.
        $middleware->append(LogApiErrors::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Keep API failures inside the { success, data, error } envelope —
        // without this, validation and auth errors return Laravel's own shape.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => $e->getMessage(),
                    'fields' => $e->errors(),
                ],
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('UNAUTHENTICATED', 'Unauthenticated.', 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('FORBIDDEN', 'This action is unauthorized.', 403);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('NOT_FOUND', 'Resource not found.', 404);
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('TOO_MANY_ATTEMPTS', 'Too many attempts. Please try again later.', 429);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('NOT_FOUND', 'Resource not found.', 404);
        });
    })->create();
