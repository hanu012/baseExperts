<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Push dispatch log (SPEC section 5.12) — one row per composed
 * campaign OR per automated trigger fired (BUILD_PLAN 7.2).
 *
 * NOT the same class as Illuminate\Notifications\Notification (the
 * thing `FcmChannel` receives to build a payload from) — same short
 * name, different purpose entirely, matching the
 * CategoryResource/BannerResource "different namespaces" precedent.
 * Wherever both are in scope (FcmChannel), Laravel's own is aliased,
 * not this one, since this one keeps its natural name as the
 * domain model everywhere else in the app.
 *
 * A single-recipient automated send (e.g. one vendor approved) is a
 * campaign of one: `audience` holds an identifying key like
 * `{"user_id": 5}` instead of a broad filter, and `sent_count`/
 * `failed_count` reflect that user's device count rather than a
 * broad audience size — the same row shape covers both scales,
 * confirmed against BUILD_PLAN 7.2's own survey.
 */
class Notification extends Model
{
    protected $fillable = [
        'title',
        'body',
        'target_app',
        'type',
        'audience',
        'link_url',
        'scheduled_at',
        'sent_at',
        'sent_count',
        'failed_count',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
