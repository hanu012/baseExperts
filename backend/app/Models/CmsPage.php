<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuditLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * CMS page (SPEC section 5 item 13): Terms, Privacy Policy, Refund
 * Policy, FAQ, About — served publicly at /pages/{slug} for app store
 * listing compliance.
 *
 * NO DELETE, deliberately — but for a different reason than SPEC
 * section 10's master-data rule (nothing references a cms_page by
 * id, so there's no orphaned-subscription risk). `slug` is a fixed
 * URL an app store listing may already point at; deleting the row
 * would 404 a URL Apple/Google's cached listing still references.
 * `is_published` is the safe takedown path instead.
 */
class CmsPage extends Model
{
    use HasFactory;
    use HasTranslations;
    use RecordsAuditLog;

    /** Stored per language (en / hi / gu); reads return the request locale, falling back to English. */
    public array $translatable = ['title', 'body'];

    protected $fillable = [
        'slug',
        'title',
        'body',
        'target_app',
        'is_published',
        'published_at',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }
}
