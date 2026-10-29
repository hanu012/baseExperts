<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\MapKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side proxy for Google Places, used by the zone map's search box.
 *
 * The browser never sees the key. A Places key is billable and an
 * unrestricted one rendered into a page is one anybody can lift and
 * spend — so the admin asks us, and we ask Google.
 *
 * Admin-only (see the route's middleware). Without that this would be an
 * open, unmetered geocoding service running on someone else's bill.
 *
 * Two steps, because that is how Google bills it: autocomplete returns
 * predictions with no coordinates, and only the prediction the admin
 * actually picks costs a Details lookup. Resolving every prediction up
 * front would multiply the bill by the length of the list.
 *
 * PLACES API (NEW). The legacy maps/api/place/* endpoints cannot be
 * enabled on Google Cloud projects created since March 2025, so a fresh
 * key gets nothing from them. The key needs "Places API (New)" enabled.
 * The JSON this returns to the map is unchanged.
 */
class PlaceLookupController extends Controller
{
    private const AUTOCOMPLETE_URL = 'https://places.googleapis.com/v1/places:autocomplete';

    private const DETAILS_URL = 'https://places.googleapis.com/v1/places/';

    /** Billed by field: geometry and the name are all the map uses. */
    private const DETAILS_FIELDS = 'id,displayName,formattedAddress,location,viewport';

    private const TIMEOUT_SECONDS = 8;

    /**
     * Predictions for what the admin has typed so far.
     */
    public function autocomplete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:200'],
            // Groups a typing session into one billable autocomplete
            // rather than one per keystroke. Supplied by the browser.
            'session' => ['nullable', 'string', 'max:64'],
        ]);

        $key = $this->key();

        if ($key === null) {
            return response()->json(['predictions' => [], 'error' => null]);
        }

        $payload = ['input' => $validated['q']];

        if (filled($validated['session'] ?? null)) {
            $payload['sessionToken'] = $validated['session'];
        }

        if (filled(config('map.geocode_country'))) {
            $payload['includedRegionCodes'] = [strtolower((string) config('map.geocode_country'))];
        }

        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders(['X-Goog-Api-Key' => $key])
            ->post(self::AUTOCOMPLETE_URL, $payload);

        if ($response->failed()) {
            $this->logFailure('Places autocomplete failed', $response);

            return $this->unavailable('Place search is unavailable right now.');
        }

        return response()->json([
            'predictions' => collect($response->json('suggestions') ?? [])
                // Query predictions ("pizza near me") carry no place to go to.
                ->pluck('placePrediction')
                ->filter()
                ->map(fn (array $prediction) => [
                    'id' => $prediction['placeId'] ?? null,
                    'main' => $prediction['structuredFormat']['mainText']['text']
                        ?? $prediction['text']['text']
                        ?? '',
                    'secondary' => $prediction['structuredFormat']['secondaryText']['text'] ?? '',
                    'label' => $prediction['text']['text'] ?? '',
                ])
                ->filter(fn (array $prediction) => filled($prediction['id']))
                ->values()
                ->all(),
            'error' => null,
        ]);
    }

    /**
     * Logged rather than shown: Google's message can name the key and the
     * reason it was refused ("Places API (New) has not been used in
     * project …"), which is for whoever reads the logs, not the browser.
     */
    private function logFailure(string $message, \Illuminate\Http\Client\Response $response): void
    {
        Log::warning($message, [
            'status' => $response->status(),
            'reason' => $response->json('error.status'),
            'message' => $response->json('error.message'),
        ]);
    }

    /**
     * The coordinates behind one prediction, plus the viewport Google
     * suggests for it — so selecting a suburb frames the suburb rather
     * than dropping a fixed zoom on its centre point.
     */
    public function details(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'place_id' => ['required', 'string', 'max:255'],
            'session' => ['nullable', 'string', 'max:64'],
        ]);

        $key = $this->key();

        if ($key === null) {
            return $this->unavailable('Place lookup is not configured.');
        }

        $query = [];

        if (filled($validated['session'] ?? null)) {
            $query['sessionToken'] = $validated['session'];
        }

        // rawurlencode: the id is a path segment here, not a query value.
        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders([
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => self::DETAILS_FIELDS,
            ])
            ->get(self::DETAILS_URL.rawurlencode($validated['place_id']), $query);

        if ($response->failed()) {
            $this->logFailure('Place details failed', $response);

            return $this->unavailable('Could not look that place up.');
        }

        $body = $response->json();
        $location = $body['location'] ?? null;

        if (! isset($location['latitude'], $location['longitude'])) {
            return $this->unavailable('That place has no coordinates.');
        }

        return response()->json([
            'place' => [
                'label' => $body['formattedAddress']
                    ?? $body['displayName']['text']
                    ?? '',
                'lat' => (float) $location['latitude'],
                'lng' => (float) $location['longitude'],
                'viewport' => $this->viewport($body['viewport'] ?? null),
            ],
            'error' => null,
        ]);
    }

    /**
     * Google's {low, high} corners as the map's south/west/north/east.
     *
     * @return array{south: float, west: float, north: float, east: float}|null
     */
    private function viewport(?array $viewport): ?array
    {
        if (! isset(
            $viewport['low']['latitude'],
            $viewport['low']['longitude'],
            $viewport['high']['latitude'],
            $viewport['high']['longitude'],
        )) {
            return null;
        }

        return [
            'south' => (float) $viewport['low']['latitude'],
            'west' => (float) $viewport['low']['longitude'],
            'north' => (float) $viewport['high']['latitude'],
            'east' => (float) $viewport['high']['longitude'],
        ];
    }

    /** Settings → Maps, else .env — see MapKeys. */
    private function key(): ?string
    {
        return MapKeys::placesKey();
    }

    private function unavailable(string $message): JsonResponse
    {
        return response()->json(['predictions' => [], 'place' => null, 'error' => $message], 200);
    }
}
