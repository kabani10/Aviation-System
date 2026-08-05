<?php

use App\Domain\Finance\Actions\CreateInvoiceFromQuotation;
use App\Domain\Finance\Actions\RecordInvoicePayment;
use App\Domain\Finance\Enums\InvoiceStatus;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;

it('marks the invoice paid and closes the flight', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::Invoiced]);
    Quotation::factory()->for($flightRequest)->create(['status' => QuotationStatus::Accepted]);
    $invoice = app(CreateInvoiceFromQuotation::class)($flightRequest);

    app(RecordInvoicePayment::class)($invoice, 'Paid by wire transfer.');

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::Paid);
    expect($invoice->paid_at)->not->toBeNull();
    expect($invoice->notes)->toBe('Paid by wire transfer.');
    expect($invoice->flightRequest->fresh()->status)->toBe(FlightStatus::Closed);

    $entry = $invoice->communications()->first();
    expect($entry->body)->toBe('Paid by wire transfer.');
});
