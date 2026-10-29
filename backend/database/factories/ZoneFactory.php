<?php

namespace Database\Factories;

use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Zone>
 */
class ZoneFactory extends Factory
{
    protected $model = Zone::class;

    /**
     * A small square around Ahmedabad by default. `polygon` is NOT NULL, so
     * every factory-made zone needs a real boundary — there is no such thing
     * as a zone without one (SPEC section 11).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement([
            'Gota', 'Ranip', 'Sola', 'Paldi', 'Ambawadi', 'Sarkhej', 'Thaltej', 'Adalaj',
        ]).' '.fake()->unique()->numberBetween(1, 999999);

        // Nudge each square so factory zones do not all sit on top of
        // each other, which would make containment tests meaningless.
        $lat = 23.0 + (fake()->numberBetween(0, 40) / 100);
        $lng = 72.5 + (fake()->numberBetween(0, 40) / 100);

        return [
            'parent_id' => null,
            'name' => $name,
            'slug' => Str::slug($name),
            'polygon' => Zone::polygonExpression(self::square($lat, $lng)),
            'pincode' => (string) fake()->numberBetween(380001, 382999),
            // SPEC section 11: zones start as drafts.
            'is_active' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => true]);
    }

    /**
     * A zone whose boundary is a known square, for containment assertions.
     *
     * @param  array<int, array{lat: float, lng: float}>|null  $points
     */
    public function withBoundary(?array $points = null, ?float $lat = null, ?float $lng = null): static
    {
        $points ??= self::square($lat ?? 23.09, $lng ?? 72.52);

        return $this->state(fn (array $attributes) => [
            'polygon' => Zone::polygonExpression($points),
        ]);
    }

    /**
     * A ~0.04 degree square with its south-west corner at the given point.
     *
     * @return array<int, array{lat: float, lng: float}>
     */
    public static function square(float $lat, float $lng, float $size = 0.04): array
    {
        return [
            ['lat' => $lat, 'lng' => $lng],
            ['lat' => $lat, 'lng' => $lng + $size],
            ['lat' => $lat + $size, 'lng' => $lng + $size],
            ['lat' => $lat + $size, 'lng' => $lng],
        ];
    }
}
