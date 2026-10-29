<?php

namespace Tests\Feature\Schema;

use App\Models\Plan;
use PHPUnit\Framework\TestCase;

/**
 * CLAUDE.md: money is stored as integer paise, never float.
 *
 * The admin form takes rupees, so there is exactly one conversion point.
 * These tests pin it — particularly the float trap: `(int) (1.15 * 100)` is
 * 114, not 115, because 1.15 has no exact IEEE-754 representation and the
 * cast truncates rather than rounds. Sweeping 0.01–200.00 finds **1145 of
 * 20000 amounts** that convert wrong this way, so it is a routine case, not
 * an exotic one. Each would undercharge by a paisa, on a value no one would
 * think to check.
 */
class MoneyConversionTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            'whole rupees' => ['999', 99900],
            'two decimals' => ['999.00', 99900],
            // Each of these converts wrong under (int) ($r * 100).
            'float trap 1.15' => ['1.15', 115],
            'float trap 0.29' => ['0.29', 29],
            'float trap 1.13' => ['1.13', 113],
            'float trap 0.57' => ['0.57', 57],
            'safe by luck' => ['10.10', 1010],
            'single decimal digit' => ['5.5', 550],
            'sub-rupee' => ['0.99', 99],
            'zero' => ['0', 0],
            'large' => ['499900.75', 49990075],
            'numeric float input' => [1499.50, 149950],
            'integer input' => [999, 99900],
        ];
    }

    /**
     * @dataProvider amounts
     */
    public function test_rupees_convert_to_paise(int|float|string $input, int $expected): void
    {
        $this->assertSame($expected, Plan::rupeesToPaise($input));
    }

    public function test_the_naive_float_multiplication_really_is_wrong(): void
    {
        // Documents why the string parse exists — if this ever stops being
        // true the workaround can be simplified away.
        //
        // Note 10.10 is NOT a failing value; it converts correctly by luck.
        // Picking a demonstration value by intuition is how you end up
        // asserting the wrong thing, so these come from an actual sweep.
        $this->assertNotSame(115, (int) (1.15 * 100));
        $this->assertSame(115, Plan::rupeesToPaise('1.15'));

        $this->assertNotSame(29, (int) (0.29 * 100));
        $this->assertSame(29, Plan::rupeesToPaise('0.29'));
    }

    public function test_no_amount_in_a_realistic_range_converts_wrong(): void
    {
        // The sweep that found the failing values in the first place, kept as
        // a regression net: every paise value from 0.01 to 200.00 must survive
        // the round trip exactly.
        $wrongUnderNaiveFloat = 0;

        for ($paise = 1; $paise <= 20000; $paise++) {
            $rupees = number_format($paise / 100, 2, '.', '');

            $this->assertSame(
                $paise,
                Plan::rupeesToPaise($rupees),
                "rupeesToPaise({$rupees}) should be {$paise}"
            );

            if ((int) ((float) $rupees * 100) !== $paise) {
                $wrongUnderNaiveFloat++;
            }
        }

        // Guards the premise: if this ever drops to zero, the float approach
        // became safe and this workaround could be reconsidered.
        $this->assertGreaterThan(
            0,
            $wrongUnderNaiveFloat,
            'Naive float multiplication no longer misconverts anything — re-evaluate the string parse.'
        );
    }

    public function test_extra_decimal_places_are_truncated_not_rounded_up(): void
    {
        // Paise is the smallest unit; anything finer is not representable.
        $this->assertSame(99999, Plan::rupeesToPaise('999.999'));
    }

    public function test_empty_and_null_are_zero(): void
    {
        $this->assertSame(0, Plan::rupeesToPaise(null));
        $this->assertSame(0, Plan::rupeesToPaise(''));
    }

    public function test_paise_convert_back_to_a_rupee_string(): void
    {
        $plan = new Plan(['price_paise' => 149950]);
        $plan->price_paise = 149950;

        $this->assertSame('1499.50', $plan->priceInRupees());
    }

    public function test_the_round_trip_is_lossless(): void
    {
        foreach (['0.01', '1.00', '10.10', '999.99', '49999.05'] as $rupees) {
            $paise = Plan::rupeesToPaise($rupees);

            $plan = new Plan;
            $plan->price_paise = $paise;

            $this->assertSame($rupees, $plan->priceInRupees(), "round trip failed for {$rupees}");
        }
    }
}
