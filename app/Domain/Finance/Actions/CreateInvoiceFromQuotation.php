<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\InvoiceStatus;
use App\Domain\Finance\Models\Invoice;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Generates a Draft Invoice from the flight's accepted Quotation — there
 * must be exactly one to invoice against, so this throws if none exists
 * rather than silently picking an arbitrary quotation. `->latest()` guards
 * the unusual case of more than one Accepted quotation on record.
 */
class CreateInvoiceFromQuotation
{
    public function __invoke(FlightRequest $flightRequest, ?User $createdBy = null, ?string $notes = null, ?Carbon $dueDate = null): Invoice
    {
        $quotation = $flightRequest->quotations()
            ->where('status', QuotationStatus::Accepted)
            ->latest()
            ->first();

        if (! $quotation) {
            throw new RuntimeException('This flight has no accepted quotation to invoice against.');
        }

        return Invoice::create([
            'flight_request_id' => $flightRequest->id,
            'quotation_id' => $quotation->id,
            'invoice_number' => $this->nextInvoiceNumber($flightRequest),
            'status' => InvoiceStatus::Draft,
            'created_by' => $createdBy?->id,
            'notes' => $notes,
            'due_date' => $dueDate,
        ]);
    }

    /**
     * withoutGlobalScopes() + an explicit company_id filter, rather than
     * trusting CompanyScope alone — this needs an accurate count scoped to
     * $flightRequest's own company regardless of whatever CurrentCompany
     * happens to be set to when this runs, not silently return 0 (or count
     * across every tenant) if the two ever diverge. Each company's
     * numbering starts at 1 and never mixes with another's.
     */
    private function nextInvoiceNumber(FlightRequest $flightRequest): string
    {
        $count = Invoice::withoutGlobalScopes()->where('company_id', $flightRequest->company_id)->count();

        return 'INV-'.Str::padLeft((string) ($count + 1), 6, '0');
    }
}
