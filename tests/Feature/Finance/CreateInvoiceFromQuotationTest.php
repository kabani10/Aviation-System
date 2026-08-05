<?php

use App\Domain\Finance\Actions\CreateInvoiceFromQuotation;
use App\Domain\Finance\Enums\InvoiceStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Actions\CreateQuotationFromServices;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Services\Models\Service;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;

it('generates a draft invoice from the accepted quotation', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    Service::factory()->for($flightRequest)->create(['cost' => 400, 'selling_price' => 600]);
    $quotation = app(CreateQuotationFromServices::class)($flightRequest);
    $quotation->update(['status' => QuotationStatus::Accepted]);

    $invoice = app(CreateInvoiceFromQuotation::class)($flightRequest);

    expect($invoice->status)->toBe(InvoiceStatus::Draft);
    expect($invoice->quotation_id)->toBe($quotation->id);
    expect($invoice->totalAmount())->toBe(600.0);
    expect($invoice->profitMargin())->toBe(200.0);
    expect($invoice->invoice_number)->toBe('INV-000001');
});

it('numbers invoices sequentially per company', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightA = FlightRequest::factory()->create();
    Quotation::factory()->for($flightA)->create(['status' => QuotationStatus::Accepted]);
    $invoiceA = app(CreateInvoiceFromQuotation::class)($flightA);

    $flightB = FlightRequest::factory()->create();
    Quotation::factory()->for($flightB)->create(['status' => QuotationStatus::Accepted]);
    $invoiceB = app(CreateInvoiceFromQuotation::class)($flightB);

    expect($invoiceA->invoice_number)->toBe('INV-000001');
    expect($invoiceB->invoice_number)->toBe('INV-000002');
});

it('starts a new company\'s invoice numbering at 1, independent of another company\'s count', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CurrentCompany::class)->set($companyA->id);
    // Two existing invoices for company A — proves company B's numbering
    // isn't derived from a shared/global counter.
    foreach (range(1, 2) as $ignored) {
        $flight = FlightRequest::factory()->create();
        Quotation::factory()->for($flight)->create(['status' => QuotationStatus::Accepted]);
        app(CreateInvoiceFromQuotation::class)($flight);
    }

    app(CurrentCompany::class)->set($companyB->id);
    $flightB = FlightRequest::factory()->create();
    Quotation::factory()->for($flightB)->create(['status' => QuotationStatus::Accepted]);
    $invoiceB = app(CreateInvoiceFromQuotation::class)($flightB);

    expect($invoiceB->invoice_number)->toBe('INV-000001');
});

it('throws when the flight has no accepted quotation', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    Quotation::factory()->for($flightRequest)->create(['status' => QuotationStatus::Rejected]);

    expect(fn () => app(CreateInvoiceFromQuotation::class)($flightRequest))
        ->toThrow(RuntimeException::class);
});
