<?php

namespace Database\Factories\Domain\FlightRequests\Models;

use App\Domain\FlightRequests\Models\FlightLeg;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\ReferenceData\Models\Airport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlightLeg>
 */
class FlightLegFactory extends Factory
{
    protected $model = FlightLeg::class;

    public function definition(): array
    {
        [$origin, $destination] = Airport::query()->inRandomOrder()->take(2)->get();
        $departure = fake()->dateTimeBetween('+1 day', '+2 weeks');

        return [
            'flight_request_id' => FlightRequest::factory(),
            'sequence' => 1,
            'origin_airport_id' => $origin->id,
            'destination_airport_id' => $destination->id,
            'departure_at' => $departure,
            'arrival_at' => (clone $departure)->modify('+'.fake()->numberBetween(1, 12).' hours'),
        ];
    }

    /** See CustomerContactFactory (Phase 3) for why this fallback is needed outside a request context. */
    public function configure(): static
    {
        return $this->afterMaking(function (FlightLeg $leg) {
            $leg->company_id ??= $leg->flightRequest?->company_id;
        });
    }
}
