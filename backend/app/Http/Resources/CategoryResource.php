<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API transformer for a category.
 *
 * NOT the same class as App\Filament\Resources\CategoryResource, which is the
 * admin panel resource. Same short name, different namespaces — never import
 * both into one file without aliasing.
 *
 * `subcategories` is only present when the relation was eager-loaded, so this
 * transformer can serve both the nested tree and any future flat listing
 * without firing a query per row.
 */
class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            // Resolved through the row's own disk, not a hardcoded path, so
            // files already moved to R2 and files still local both return a
            // working URL during the migration.
            'icon_url' => $this->fileUrl(),
            'sort_order' => $this->sort_order,
            'subcategories' => SubcategoryResource::collection(
                $this->whenLoaded('subcategories')
            ),
        ];
    }
}
