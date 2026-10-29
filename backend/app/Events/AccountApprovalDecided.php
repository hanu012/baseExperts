<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An admin approved or rejected an account. Pushed live to that user's
 * private channel so the app's "Your account is in verification" screen
 * moves on by itself instead of waiting for a manual refresh.
 *
 * Sent now rather than queued: the whole point is that it arrives while the
 * admin is still looking at the panel, and this project does not rely on a
 * queue worker running.
 */
class AccountApprovalDecided implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public readonly User $user) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->user->getKey());
    }

    public function broadcastAs(): string
    {
        return 'account.approval';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'approval_status' => $this->user->approval_status->value,
            'approval_note' => $this->user->approval_note,
        ];
    }
}
