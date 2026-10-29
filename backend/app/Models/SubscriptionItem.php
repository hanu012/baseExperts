<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One selected category/subcategory/zone against a subscription (SPEC
 * section 6). No Eloquent relation to the selected item itself — `item_id`
 * carries no foreign key, since it points at three different tables
 * depending on `item_type` (see the migration and
 * PreventsDeletionWhileSubscribed).
 *
 * No RecordsAuditLog: these rows are immutable junction records, created
 * once inside the owning Subscription's transaction and never updated. The
 * Subscription's own audit entry already covers "what was bought" via its
 * created snapshot.
 */
class SubscriptionItem extends Model
{
    protected $fillable = [
        'subscription_id',
        'item_type',
        'item_id',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
