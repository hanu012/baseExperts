<?php

namespace App\Notifications\Channels;

use App\Models\Notification as NotificationLog;
use App\Services\Fcm\FcmClient;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * The one place that actually talks to FCM (BUILD_PLAN 7.2). Sends to
 * every device the notifiable has registered, then writes exactly one
 * row to the `notifications` dispatch log per notification fired —
 * see App\Models\Notification's own docblock for why a single-
 * recipient send is a campaign of one, not a schema mismatch.
 *
 * NEVER lets a send failure propagate to the caller — a stale device
 * token or a transient FCM outage must not break the business action
 * that triggered it (e.g. LeadController::store() recording a lead).
 * Failures are caught and counted in `failed_count` instead.
 */
class FcmChannel
{
    public function __construct(private readonly FcmClient $client) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toFcm')) {
            return;
        }

        $payload = $notification->toFcm($notifiable);

        $sentCount = 0;
        $failedCount = 0;

        foreach ($notifiable->deviceTokens()->pluck('token') as $token) {
            try {
                $sent = $this->client->send($token, [
                    'notification' => [
                        'title' => $payload['title'],
                        'body' => $payload['body'],
                    ],
                    'data' => array_map('strval', $payload['data'] ?? []),
                ]);

                $sent ? $sentCount++ : $failedCount++;
            } catch (Throwable) {
                $failedCount++;
            }
        }

        NotificationLog::create([
            'title' => $payload['title'],
            'body' => $payload['body'],
            'target_app' => $payload['target_app'] ?? null,
            'type' => $payload['type'] ?? 'automated',
            'audience' => ['user_id' => $notifiable->getKey()],
            'sent_at' => now(),
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
        ]);
    }
}
