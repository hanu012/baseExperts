<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API transformer for a banner (SPEC section 5 item 5).
 *
 * NOT the same class as App\Filament\Resources\BannerResource, which
 * is the admin panel resource. Same short name, different namespaces
 * — same collision CategoryResource's own docblock warns about.
 *
 * click_count is deliberately absent: it's an admin-facing metric,
 * not something the serving response needs to hand back to the app
 * that's about to click it.
 */
class BannerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'position' => $this->position,
            'image_url' => $this->fileUrl(),
            'link_url' => $this->link_url,
        ];
    }
}
