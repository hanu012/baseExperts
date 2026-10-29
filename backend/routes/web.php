<?php

use App\Http\Controllers\Admin\PlaceLookupController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PasswordResetWebController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Public CMS pages (SPEC section 5 item 13) — the actual browsable
// page app store reviewers/users need, not a JSON API response.
// Registered before /pages/{slug} so "index" is never matched as a
// slug by the wildcard route below.
Route::get('/pages', [PageController::class, 'index'])->name('pages.index');
Route::get('/pages/{slug}', [PageController::class, 'show'])->name('pages.show');

// "Set a new password" pages that reset emails link to (Settings →
// Password reset; see PasswordResetWebController). Public — the link is
// the credential — and rate limited per IP. `password.reset` is the name
// Laravel's own reset email builds its link from.
Route::middleware('throttle:password-reset-web')->group(function () {
    Route::get('/password/reset/{token}', [PasswordResetWebController::class, 'showLaravel'])
        ->name('password.reset');
    Route::post('/password/reset', [PasswordResetWebController::class, 'resetLaravel'])
        ->name('password.update');

    Route::get('/password/firebase-action', [PasswordResetWebController::class, 'showFirebase'])
        ->name('password.firebase.show');
    Route::post('/password/firebase-action', [PasswordResetWebController::class, 'resetFirebase'])
        ->name('password.firebase.update');
});

// Google Places lookup for the admin zone map's search box.
//
// Admin-only and behind the same role check the panel itself uses:
// without that this is an open, unmetered geocoding service running on
// our Google bill. The key stays server-side — see PlaceLookupController.
Route::middleware(['auth', 'role:admin'])
    ->prefix('admin/places')
    ->name('admin.places.')
    ->group(function () {
        Route::get('/autocomplete', [PlaceLookupController::class, 'autocomplete'])
            ->name('autocomplete');

        Route::get('/details', [PlaceLookupController::class, 'details'])
            ->name('details');
    });
