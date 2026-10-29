<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A KYC document the admin asks vendors for (Aadhaar, PAN, Driving
 * Licence…) — Admin → Document Types. Says what each upload needs and
 * whether it is compulsory for approval. See VendorKycService.
 *
 * Never deleted once used (vendor_documents restricts it): deactivate it
 * instead, which drops it from every vendor's list and from the approval
 * rule while keeping what was uploaded.
 */
class DocumentType extends Model
{
    use RecordsAuditLog;

    protected $fillable = [
        'code',
        'name',
        'description',
        'has_number',
        'number_label',
        'number_hint',
        'number_pattern',
        'mask_number',
        'has_expiry',
        'has_back',
        'is_compulsory',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'has_number' => 'boolean',
            'mask_number' => 'boolean',
            'has_expiry' => 'boolean',
            'has_back' => 'boolean',
            'is_compulsory' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function vendorDocuments(): HasMany
    {
        return $this->hasMany(VendorDocument::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
