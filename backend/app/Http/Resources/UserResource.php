<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicit allow-list of user fields exposed over the API, so columns added
 * in later phases are never leaked to clients by default.
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Editable on every app's profile (PATCH /api/user/profile,
            // POST /api/user/avatar).
            'phone' => $this->profilePhone(),
            'avatar_url' => $this->avatarUrl(),
            'role' => $this->role->value,
            // Drives the forced change-password screen on first login
            // (SPEC section 2.1). Enforced server-side too - see
            // RequirePasswordChange middleware.
            'must_change_password' => (bool) $this->must_change_password,
            // Drives which screen the app opens after sign-in (SPEC section
            // 3.1): anything but 'approved' goes to the pending screen
            // instead of home. Enforced server-side too — see
            // RequireApprovedAccount middleware.
            'approval_status' => $this->approval_status->value,
            // Only meaningful on a rejection, where it carries the admin's
            // reason so the app can show it rather than a dead end.
            'approval_note' => $this->approval_note,
            'approval_decided_at' => $this->approval_decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
