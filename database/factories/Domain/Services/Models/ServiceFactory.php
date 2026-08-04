<?php

namespace Database\Factories\Domain\Services\Models;

use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Shared\Enums\ServiceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            // A single nested factory, not two of the same related model —
            // see ARCHITECTURE.md's "Flight Requests" note on why
            // FlightRequestFactory itself can't do this for Customer/Aircraft.
            'flight_request_id' => FlightRequest::factory(),
            'type' => fake()->randomElement(ServiceType::cases()),
            'status' => ServiceStatus::NotStarted,
            'cost' => fake()->randomFloat(2, 100, 5000),
            'selling_price' => fake()->randomFloat(2, 150, 6000),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Service $service) {
            $service->company_id ??= $service->flightRequest?->company_id;
        });
    }
}
