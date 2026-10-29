<?php

namespace App\Notifications;

use App\Models\VendorDocument;
use App\Notifications\Channels\FcmChannel;
use Illuminate\Notifications\Notification;

/**
 * A KYC document needs the vendor's attention: an admin asked for it again
 * ('reupload', with their reason) or it passed its expiry date ('expired').
 * See VendorKycService. Opens the vendor app's documents screen.
 */
class VendorDocumentNotification extends Notification
{
    public function __construct(
        private readonly VendorDocument $document,
        private readonly string $reason,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [FcmChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toFcm(object $notifiable): array
    {
        $name = $this->document->documentType?->name ?? 'A document';

        return [
            'title' => $this->reason === 'expired'
                ? "{$name} has expired"
                : "Please upload your {$name} again",
            'body' => $this->reason === 'expired'
                ? 'Upload a current copy to keep your account verified.'
                : ($this->document->review_note ?? 'Open the app to see why.'),
            'type' => $this->reason === 'expired' ? 'document_expired' : 'document_reupload',
            'target_app' => 'vendor',
            'data' => [
                'vendor_id' => $this->document->vendor_id,
                'document_id' => $this->document->id,
            ],
        ];
    }
}
