<?php

use App\Domain\Customers\Models\Customer;
use App\Domain\Finance\Actions\ComputeFinancialSummary;
use App\Domain\Finance\Actions\CreateInvoiceFromQuotation;
use App\Domain\Finance\Actions\RecordInvoicePayment;
use App\Domain\Finance\Actions\SendInvoice;
use App\Domain\Finance\Models\Invoice;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Actions\CreateQuotationFromServices;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;

function acceptedInvoiceFor(FlightRequest $flightRequest, float $cost, float $sellingPrice): Invoice
{
    Service::factory()->for($flightRequest)->create(['cost' => $cost, 'selling_price' => $sellingPrice]);
    $quotation = app(CreateQuotationFromServices::class)($flightRequest);
    $quotation->update(['status' => QuotationStatus::Accepted]);

    return app(CreateInvoiceFromQuotation::class)($flightRequest);
}

it('sums invoiced, collected, outstanding, overdue, and profit margin correctly', function () {
    Mail::fake();

    $company = Company::factory()->create();
    $customer = Customer::factory()->for($company)->create(['billing_email' => 'x@customer.com']);
    app(CurrentCompany::class)->set($company->id);

    // Paid: cost 400, price 1000 -> margin 600
    $paidFlight = FlightRequest::factory()->create(['customer_id' => $customer->id]);
    $paidInvoice = acceptedInvoiceFor($paidFlight, 400, 1000);
    app(SendInvoice::class)($paidInvoice);
    app(RecordInvoicePayment::class)($paidInvoice->fresh());

    // Sent, not yet due -> outstanding, not overdue
    $sentFlight = FlightRequest::factory()->create(['customer_id' => $customer->id]);
    $sentInvoice = acceptedInvoiceFor($sentFlight, 100, 500);
    $sentInvoice->update(['due_date' => now()->addWeek()]);
    app(SendInvoice::class)($sentInvoice->fresh());

    // Sent, overdue
    $overdueFlight = FlightRequest::factory()->create(['customer_id' => $customer->id]);
    $overdueInvoice = acceptedInvoiceFor($overdueFlight, 50, 300);
    $overdueInvoice->update(['due_date' => now()->subDay()]);
    app(SendInvoice::class)($overdueInvoice->fresh());

    // Draft — should not count toward anything
    $draftFlight = FlightRequest::factory()->create(['customer_id' => $customer->id]);
    acceptedInvoiceFor($draftFlight, 10, 20);

    $summary = app(ComputeFinancialSummary::class)();

    expect($summary->totalInvoiced)->toBe(1000.0 + 500.0 + 300.0);
    expect($summary->totalCollected)->toBe(1000.0);
    expect($summary->totalOutstanding)->toBe(500.0 + 300.0);
    expect($summary->overdueCount)->toBe(1);
    expect($summary->overdueAmount)->toBe(300.0);
    expect($summary->totalProfitMargin)->toBe(600.0);
});

it('returns a null profit margin when nothing has been paid yet', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    acceptedInvoiceFor($flightRequest, 100, 200);

    $summary = app(ComputeFinancialSummary::class)();

    expect($summary->totalProfitMargin)->toBeNull();
});

it('never mixes another company\'s invoices into the summary', function () {
    Mail::fake();

    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CurrentCompany::class)->set($companyA->id);
    $customerA = Customer::factory()->for($companyA)->create(['billing_email' => 'a@customer.com']);
    $flightA = FlightRequest::factory()->create(['customer_id' => $customerA->id]);
    $invoiceA = acceptedInvoiceFor($flightA, 100, 900);
    app(SendInvoice::class)($invoiceA);
    app(RecordInvoicePayment::class)($invoiceA->fresh());

    app(CurrentCompany::class)->set($companyB->id);
    $customerB = Customer::factory()->for($companyB)->create(['billing_email' => 'b@customer.com']);
    $flightB = FlightRequest::factory()->create(['customer_id' => $customerB->id]);
    $invoiceB = acceptedInvoiceFor($flightB, 999, 9999);
    app(SendInvoice::class)($invoiceB);
    app(RecordInvoicePayment::class)($invoiceB->fresh());

    $summaryB = app(ComputeFinancialSummary::class)();
    expect($summaryB->totalCollected)->toBe(9999.0);

    app(CurrentCompany::class)->set($companyA->id);
    $summaryA = app(ComputeFinancialSummary::class)();
    expect($summaryA->totalCollected)->toBe(900.0);
});
