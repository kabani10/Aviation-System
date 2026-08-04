<?php

namespace Database\Factories\Domain\FlightRequests\Models;

use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\Customers\Models\Customer;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\ReferenceData\Models\Airport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlightRequest>
 */
class FlightRequestFactory extends Factory
{
    protected $model = FlightRequest::class;

    public function definition(): array
    {
        // Created eagerly rather than as lazy `Model::factory()` values —
        // Aircraft's own factory also nests a Customer::factory(), and
        // having two co-dependent nested factories of the same related
        // model in one definition() trips a bug in Laravel's factory
        // relationship-recycling (empty relationship name passed to
        // BelongsToRelationship, throws inside parentResolvers()).
        // configure() below re-points aircraft_id if ->for() overrides
        // customer_id after the fact.
        $customer = Customer::factory()->create();
        $aircraft = Aircraft::factory()->for($customer)->create();

        [$origin, $destination] = Airport::query()->inRandomOrder()->take(2)->get();
        $departure = fake()->dateTimeBetween('+1 day', '+2 weeks');

        return [
            'customer_id' => $customer->id,
            'aircraft_id' => $aircraft->id,
            'callsign' => $aircraft->registration,
            'origin_airport_id' => $origin->id,
            'destination_airport_id' => $destination->id,
            'departure_at' => $departure,
            'arrival_at' => (clone $departure)->modify('+'.fake()->numberBetween(1, 12).' hours'),
            'passenger_count' => fake()->numberBetween(1, 14),
            'crew_count' => fake()->numberBetween(2, 4),
            'status' => FlightStatus::NewRequest,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (FlightRequest $flightRequest) {
            if ($flightRequest->aircraft?->customer_id !== $flightRequest->customer_id) {
                $flightRequest->aircraft_id = Aircraft::factory()
                    ->for($flightRequest->customer)
                    ->create()
                    ->id;
            }

            $flightRequest->company_id ??= $flightRequest->customer?->company_id;
        });
    }
}
