<?php

namespace Database\Factories\Domain\ReferenceData\Models;

use App\Domain\ReferenceData\Models\Airport;
use App\Domain\ReferenceData\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Airport>
 */
class AirportFactory extends Factory
{
    protected $model = Airport::class;

    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'icao_code' => strtoupper(fake()->unique()->lexify('????')),
            'iata_code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->city().' International',
            'city' => fake()->city(),
        ];
    }
}
