<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateAvatarRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The signed-in user's own personal details and photo, shared by all three
 * apps' profile screens:
 *
 *  - PATCH  /api/user/profile — name, email, phone (UpdateProfileRequest)
 *  - POST   /api/user/avatar  — set the profile photo
 *  - DELETE /api/user/avatar  — remove it
 *
 * Each answers with the updated user (UserResource), which the apps store
 * as their copy. Role-specific details stay on their own endpoints: a
 * vendor's business fields on PATCH /vendors/me.
 */
class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($request, $user) {
            $changes = [];

            if ($request->has('name')) {
                $changes['name'] = trim($request->string('name')->toString());
            }

            if ($request->emailIsChanging()) {
                $changes['email'] = trim($request->string('email')->toString());
            }

            if ($changes !== []) {
                $user->update($changes);
            }

            if ($request->has('phone')) {
                $this->updatePhone($user, $request->input('phone'));
            }
        });

        return ApiResponse::success(new UserResource($user->fresh()));
    }

    public function updateAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $previousDisk = $user->avatar_disk;
        $previousPath = $user->avatar_path;

        $disk = Vendor::currentUploadDisk();
        $path = $request->file('photo')->store('avatars/'.$user->getKey(), $disk);

        $user->forceFill(['avatar_disk' => $disk, 'avatar_path' => $path])->save();

        $this->deleteFile($previousDisk, $previousPath);

        return ApiResponse::success(new UserResource($user->fresh()));
    }

    public function destroyAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->deleteFile($user->avatar_disk, $user->avatar_path);

        $user->forceFill(['avatar_disk' => null, 'avatar_path' => null])->save();

        return ApiResponse::success(new UserResource($user->fresh()));
    }

    /** The phone lives on the role's own row; see User::profilePhone(). */
    private function updatePhone(User $user, mixed $phone): void
    {
        $phone = is_string($phone) && trim($phone) !== '' ? trim($phone) : null;

        match ($user->role) {
            UserRole::Customer => ($user->customer ?? Customer::create(['user_id' => $user->getKey()]))
                ->update(['phone' => $phone]),
            UserRole::Vendor => $user->vendor?->update(['phone' => $phone]),
            UserRole::Salesman => $user->salesman?->update(['phone' => $phone]),
            default => null,
        };
    }

    /** Best-effort: a stray old file is not worth failing the request over. */
    private function deleteFile(?string $disk, ?string $path): void
    {
        if (blank($path)) {
            return;
        }

        try {
            Storage::disk($disk ?? Vendor::currentUploadDisk())->delete($path);
        } catch (\Throwable $e) {
            Log::warning('Old profile photo could not be deleted', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }
}
