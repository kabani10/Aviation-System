<?php

namespace Database\Factories\Domain\Finance\Models;

use App\Domain\Finance\Enums\InvoiceStatus;
use App\Domain\Finance\Models\Invoice;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        // Same "eager, not lazy nested factories" approach as FlightRequestFactory —
        // an Invoice's flight_request_id and quotation_id must point at the
        // *same* flight, which two independent lazy factories can't guarantee.
        $flightRequest = FlightRequest::factory()->create();
        $quotation = Quotation::factory()->for($flightRequest)->create(['status' => QuotationStatus::Accepted]);

        return [
            'flight_request_id' => $flightRequest->id,
            'quotation_id' => $quotation->id,
            'invoice_number' => 'INV-'.Str::padLeft((string) fake()->unique()->numberBetween(1, 999999), 6, '0'),
            'status' => InvoiceStatus::Draft,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Invoice $invoice) {
            $invoice->company_id ??= $invoice->flightRequest?->company_id;
        });
    }
}
