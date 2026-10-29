<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The zone map's place search (PlaceLookupController) against Google
 * Places API (New), faked — no network, no key spent.
 */
class PlaceLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('map.google_api_key', 'server-places-key');
        config()->set('map.geocode_country', 'in');
    }

    private function admin(): User
    {
        return User::factory()->role(UserRole::Admin)->create();
    }

    public function test_autocomplete_returns_place_predictions_in_the_maps_shape(): void
    {
        Http::fake([
            'places.googleapis.com/v1/places:autocomplete' => Http::response([
                'suggestions' => [
                    ['placePrediction' => [
                        'placeId' => 'ChIJabc',
                        'text' => ['text' => 'Shivalik Heights, Satellite, Ahmedabad'],
                        'structuredFormat' => [
                            'mainText' => ['text' => 'Shivalik Heights'],
                            'secondaryText' => ['text' => 'Satellite, Ahmedabad'],
                        ],
                    ]],
                    // A query prediction has no place to go to — skipped.
                    ['queryPrediction' => ['text' => ['text' => 'shivalik near me']]],
                ],
            ]),
        ]);

        $this->actingAs($this->admin())
            ->getJson(route('admin.places.autocomplete', ['q' => 'shivalik', 'session' => 'tok-1']))
            ->assertOk()
            ->assertJsonCount(1, 'predictions')
            ->assertJsonPath('predictions.0.id', 'ChIJabc')
            ->assertJsonPath('predictions.0.main', 'Shivalik Heights')
            ->assertJsonPath('predictions.0.secondary', 'Satellite, Ahmedabad');

        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Goog-Api-Key', 'server-places-key')
            && $request['input'] === 'shivalik'
            && $request['sessionToken'] === 'tok-1'
            && $request['includedRegionCodes'] === ['in']);
    }

    public function test_details_returns_the_point_and_viewport(): void
    {
        Http::fake([
            'places.googleapis.com/v1/places/*' => Http::response([
                'id' => 'ChIJabc',
                'displayName' => ['text' => 'Shivalik Heights'],
                'formattedAddress' => 'Shivalik Heights, Satellite, Ahmedabad, Gujarat',
                'location' => ['latitude' => 23.03, 'longitude' => 72.51],
                'viewport' => [
                    'low' => ['latitude' => 23.02, 'longitude' => 72.50],
                    'high' => ['latitude' => 23.04, 'longitude' => 72.52],
                ],
            ]),
        ]);

        $this->actingAs($this->admin())
            ->getJson(route('admin.places.details', ['place_id' => 'ChIJabc']))
            ->assertOk()
            ->assertJsonPath('place.lat', 23.03)
            ->assertJsonPath('place.lng', 72.51)
            ->assertJsonPath('place.viewport.south', 23.02)
            ->assertJsonPath('place.viewport.east', 72.52)
            ->assertJsonPath('place.label', 'Shivalik Heights, Satellite, Ahmedabad, Gujarat');

        Http::assertSent(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/v1/places/ChIJabc')
            && $request->hasHeader('X-Goog-FieldMask'));
    }

    public function test_a_google_error_is_reported_without_leaking_its_message(): void
    {
        Http::fake([
            'places.googleapis.com/*' => Http::response([
                'error' => ['status' => 'PERMISSION_DENIED', 'message' => 'Places API (New) has not been used in project 123'],
            ], 403),
        ]);

        $this->actingAs($this->admin())
            ->getJson(route('admin.places.autocomplete', ['q' => 'shivalik']))
            ->assertOk()
            ->assertJsonPath('error', 'Place search is unavailable right now.')
            ->assertDontSee('project 123');
    }

    public function test_without_a_key_nothing_is_sent_to_google(): void
    {
        config()->set('map.google_api_key', null);
        Http::fake();

        $this->actingAs($this->admin())
            ->getJson(route('admin.places.autocomplete', ['q' => 'shivalik']))
            ->assertOk()
            ->assertJsonCount(0, 'predictions');

        Http::assertNothingSent();
    }

    public function test_a_non_admin_cannot_use_it(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->role(UserRole::Vendor)->create())
            ->getJson(route('admin.places.autocomplete', ['q' => 'shivalik']))
            ->assertForbidden();

        Http::assertNothingSent();
    }
}
