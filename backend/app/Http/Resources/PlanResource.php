<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API transformer for a plan, with its quota flattened onto it — a quota has
 * no meaning apart from its plan (see PlanQuota's own docblock), so callers
 * shouldn't have to join it back together themselves.
 *
 * Field list mirrors Plan::auditSnapshotAttributes(), the closest thing to a
 * canonical "what does this plan offer" list in the codebase.
 */
class PlanResource extends JsonResource
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
            'description' => $this->description,
            'price_paise' => $this->price_paise,
            'price_rupees' => $this->priceInRupees(),
            'duration_days' => $this->duration_days,
            'sort_order' => $this->sort_order,
            'max_categories' => $this->whenLoaded('quota', fn () => $this->quota->max_categories),
            'max_subcategories' => $this->whenLoaded('quota', fn () => $this->quota->max_subcategories),
            'max_zones' => $this->whenLoaded('quota', fn () => $this->quota->max_zones),
            'max_photos' => $this->whenLoaded('quota', fn () => $this->quota->max_photos),
            'max_videos' => $this->whenLoaded('quota', fn () => $this->quota->max_videos),
            'priority_rank' => $this->whenLoaded('quota', fn () => $this->quota->priority_rank),
        ];
    }
}
