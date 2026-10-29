<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API transformer for a subcategory — the level vendor matching happens at
 * (SPEC section 4.4).
 */
class SubcategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'slug' => $this->slug,
            // See CategoryResource: resolved through the row's recorded disk.
            'icon_url' => $this->fileUrl(),
            'sort_order' => $this->sort_order,
            // Backs the customer subcategories screen's type filter chips
            // and "Popular" badge. service_type is nullable by design —
            // an unclassified subcategory still matches the "All" chip.
            'service_type' => $this->service_type,
            'is_popular' => (bool) $this->is_popular,
        ];
    }
}
