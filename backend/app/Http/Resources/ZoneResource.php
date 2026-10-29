<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API transformer for a zone — the salesman/vendor selection tree (SPEC
 * section 8, leaf-only matching).
 *
 * `is_leaf` is computed off the already eager-loaded `children` relation
 * rather than calling Zone::isLeaf() (which fires its own query per row) —
 * same reasoning as CategoryResource only exposing `subcategories`
 * `whenLoaded`. A top-level zone with no children (a newly added city, not
 * yet subdivided) is a leaf per SPEC section 8 and reports `is_leaf: true`
 * even though it has no parent — leaf-ness is about children, not depth.
 */
class ZoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'pincode' => $this->pincode,
            'is_leaf' => ! $this->relationLoaded('children') || $this->children->isEmpty(),
            'children' => ZoneResource::collection($this->whenLoaded('children')),
        ];
    }
}
