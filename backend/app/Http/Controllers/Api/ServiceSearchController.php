<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Subcategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Free-text service lookup for the customer's search bar (SPEC section 4
 * items 3-4).
 *
 * Searches subcategories, because that is the unit vendor matching
 * actually happens on — a customer typing "gas filling" wants the
 * vendors who do that specific thing, not everyone filed under AC
 * Service.
 *
 * Separate from /categories rather than a parameter on it: that endpoint
 * returns the whole tree as cache-on-launch master data and is
 * deliberately unpaginated, which is the opposite shape to a query that
 * returns a short ranked slice.
 *
 * Public, same reasoning as /categories — customers browse before
 * signing in.
 */
class ServiceSearchController extends Controller
{
    /**
     * Hard ceiling on returned rows. A search box shows a handful;
     * anything past that is noise the customer will refine rather than
     * scroll.
     */
    private const LIMIT = 30;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Two characters is where results start being about the
            // query rather than about the whole catalogue.
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $term = trim($validated['q']);

        // Escape the LIKE wildcards themselves, or a customer typing "%"
        // matches everything and "_" matches any single character.
        $escaped = addcslashes($term, '%_\\');

        $matches = Subcategory::query()
            ->active()
            ->whereHas('category', fn ($query) => $query->active())
            ->with('category')
            // Names are stored per language as JSON. JSON_SEARCH matches the
            // values in every language — so a customer can type Hindi or
            // Gujarati — and never the "en"/"hi"/"gu" keys, which a plain
            // LIKE would match for a two-letter query.
            ->where(function ($query) use ($escaped) {
                $query
                    ->whereRaw("JSON_SEARCH(subcategories.name, 'one', ?) IS NOT NULL", ["%{$escaped}%"])
                    // Also matches the parent, so "AC" finds every
                    // service under AC Service even where the service's
                    // own name does not contain it.
                    ->orWhereHas(
                        'category',
                        fn ($parent) => $parent->whereRaw("JSON_SEARCH(categories.name, 'one', ?) IS NOT NULL", ["%{$escaped}%"])
                    );
            })
            // A prefix match is almost always what was meant, so those
            // sort first rather than being scattered through the list in
            // catalogue order.
            ->orderByRaw("CASE WHEN JSON_SEARCH(subcategories.name, 'one', ?) IS NOT NULL THEN 0 ELSE 1 END", ["{$escaped}%"])
            ->orderBy('subcategories.sort_order')
            ->orderBy('subcategories.name')
            ->limit(self::LIMIT)
            ->get();

        return ApiResponse::success([
            'services' => $matches->map(fn (Subcategory $subcategory) => [
                'id' => $subcategory->id,
                'name' => $subcategory->name,
                'slug' => $subcategory->slug,
                'icon_url' => $subcategory->fileUrl(),
                'category_id' => $subcategory->category_id,
                'category_name' => $subcategory->category?->name,
            ])->all(),
        ]);
    }
}
