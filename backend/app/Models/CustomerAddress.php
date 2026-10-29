<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One saved address in a customer's address book. See the migration for how
 * it relates to the customer's current location.
 */
class CustomerAddress extends Model
{
    public const LABELS = ['home', 'office', 'other'];

    /** Bounded so the list can be returned whole, without pagination. */
    public const MAX_PER_CUSTOMER = 20;

    protected $fillable = [
        'label',
        'other_label',
        'apartment_number',
        'building_name',
        'street_address',
        'area',
        'city',
        'state',
        'pincode',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            // float, not decimal — see Customer: the decimal cast returns a
            // string and the apps parse a number.
            'latitude' => 'float',
            'longitude' => 'float',
            'is_default' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Makes this the customer's only default address. is_default is not
     * fillable, so this is the one way it changes.
     */
    public function makeDefault(): void
    {
        DB::transaction(function () {
            static::query()
                ->where('customer_id', $this->customer_id)
                ->whereKeyNot($this->getKey())
                ->update(['is_default' => false]);

            $this->forceFill(['is_default' => true])->save();
        });
    }

    /** "Home", "Office", or the customer's own name for an "other". */
    public function displayLabel(): string
    {
        if ($this->label === 'other' && filled($this->other_label)) {
            return $this->other_label;
        }

        return ucfirst($this->label);
    }
}
