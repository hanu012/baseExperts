<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Notifications\Channels\FcmChannel;
use Illuminate\Notifications\Notification;

/**
 * SPEC section 5.12's "lead received" trigger — new (no prior stub
 * existed). Sent to the VENDOR: a customer just tapped Call or
 * WhatsApp on their listing.
 */
class LeadReceivedNotification extends Notification
{
    public function __construct(private readonly Lead $lead) {}

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
        return [
            'title' => 'New lead received',
            'body' => "A customer just reached out via {$this->lead->channel}.",
            'type' => 'lead_received',
            'target_app' => 'vendor',
            'data' => ['lead_id' => $this->lead->id],
        ];
    }
}
