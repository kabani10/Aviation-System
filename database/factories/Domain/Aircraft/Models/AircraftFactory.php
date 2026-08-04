<?php

namespace Database\Factories\Domain\Aircraft\Models;

use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Aircraft>
 */
class AircraftFactory extends Factory
{
    protected $model = Aircraft::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'registration' => strtoupper('N'.fake()->unique()->numerify('###').fake()->randomLetter()),
            'aircraft_type' => fake()->randomElement([
                'Gulfstream G650', 'Bombardier Global 6000', 'Dassault Falcon 7X',
                'Cessna Citation X', 'Embraer Legacy 650',
            ]),
            'mtow_kg' => fake()->numberBetween(8000, 45000),
            'is_active' => true,
        ];
    }

    /** See CustomerContactFactory — same reasoning. */
    public function configure(): static
    {
        return $this->afterMaking(function (Aircraft $aircraft) {
            $aircraft->company_id ??= $aircraft->customer?->company_id;
        });
    }
}
