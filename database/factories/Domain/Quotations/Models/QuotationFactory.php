<?php

namespace Database\Factories\Domain\Quotations\Models;

use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quotation>
 */
class QuotationFactory extends Factory
{
    protected $model = Quotation::class;

    public function definition(): array
    {
        return [
            'flight_request_id' => FlightRequest::factory(),
            'status' => QuotationStatus::Draft,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Quotation $quotation) {
            $quotation->company_id ??= $quotation->flightRequest?->company_id;
        });
    }
}
