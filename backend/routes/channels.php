<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| One private channel per user, which only that user may listen on. The
| apps subscribe to it after signing in (POST /api/broadcasting/auth, with
| their Sanctum token) — see the Flutter SocketService.
|
*/

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id): bool {
    return (int) $user->getKey() === $id;
});
