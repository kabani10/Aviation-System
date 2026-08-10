<?php

namespace Database\Factories\Domain\FlightRequests\Models;

use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\Customers\Models\Customer;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightLeg;
use App\Domain\FlightRequests\Models\FlightRequest;
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

        return [
            'customer_id' => $customer->id,
            'aircraft_id' => $aircraft->id,
            'callsign' => $aircraft->registration,
            'passenger_count' => fake()->numberBetween(1, 14),
            'crew_count' => fake()->numberBetween(2, 4),
            'status' => FlightStatus::NewRequest,
        ];
    }

    public function configure(): static
    {
        return $this
            ->afterMaking(function (FlightRequest $flightRequest) {
                if ($flightRequest->aircraft?->customer_id !== $flightRequest->customer_id) {
                    $flightRequest->aircraft_id = Aircraft::factory()
                        ->for($flightRequest->customer)
                        ->create()
                        ->id;
                }

                $flightRequest->company_id ??= $flightRequest->customer?->company_id;
            })
            ->afterCreating(function (FlightRequest $flightRequest) {
                // Every flight has at least one leg in practice — a
                // factory-made one is no exception, so callers that only
                // care about the flight itself (most tests) don't have to
                // build a leg by hand just to get a valid record.
                if ($flightRequest->legs()->doesntExist()) {
                    FlightLeg::factory()->for($flightRequest)->create(['sequence' => 1]);
                }
            });
    }
}
