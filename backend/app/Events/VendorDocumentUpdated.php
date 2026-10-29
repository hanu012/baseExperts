<?php

namespace App\Events;

use App\Models\VendorDocument;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A vendor's KYC document changed status (verified, under review, sent
 * back, expired, uploaded — VendorKycService). Pushed live to the vendor's
 * own private channel so the app's document screens redraw at once instead
 * of waiting for a refresh. The app re-reads GET vendors/me/documents on it;
 * the payload is only a hint.
 *
 * Sent now rather than queued, for the same reason as AccountApprovalDecided.
 */
class VendorDocumentUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly int $userId,
        public readonly VendorDocument $document,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'kyc.document';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'document_id' => $this->document->id,
            'document_type_id' => $this->document->document_type_id,
            'status' => $this->document->status,
        ];
    }
}
