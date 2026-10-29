<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;

/**
 * Approve/Reject transitions for the media moderation queue (SPEC section
 * 5.10, task 4.5) — pulled out of the Filament actions so the state
 * transition lives in one place, same shape as VendorVerificationService.
 *
 * No push-notification seam here — not asked for in this task, unlike
 * vendor verification's explicit ask (task 4.3).
 */
class MediaModerationService
{
    public function approve(Media $media, User $admin): void
    {
        $media->update([
            'moderation_status' => 'approved',
            'moderated_by' => $admin->id,
            'moderated_at' => now(),
            'rejection_reason' => null,
        ]);
    }

    public function reject(Media $media, User $admin, string $reason): void
    {
        $media->update([
            'moderation_status' => 'rejected',
            'moderated_by' => $admin->id,
            'moderated_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }
}
