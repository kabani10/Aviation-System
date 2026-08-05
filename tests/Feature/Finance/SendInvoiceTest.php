<?php

use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Customers\Models\Customer;
use App\Domain\Finance\Actions\CreateInvoiceFromQuotation;
use App\Domain\Finance\Actions\SendInvoice;
use App\Domain\Finance\Enums\InvoiceStatus;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Tenancy\Models\Company;
use App\Mail\InvoiceMail;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;

it('emails the customer, logs it, and moves the invoice and flight forward', function () {
    Mail::fake();

    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create(['billing_email' => 'billing@customer.com']);
    $flightRequest = FlightRequest::factory()->create(['customer_id' => $customer->id]);
    Quotation::factory()->for($flightRequest)->create(['status' => QuotationStatus::Accepted]);
    $invoice = app(CreateInvoiceFromQuotation::class)($flightRequest);

    app(SendInvoice::class)($invoice);

    Mail::assertSent(InvoiceMail::class, fn ($mail) => $mail->hasTo('billing@customer.com') && $mail->invoice->is($invoice));

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::Sent);
    expect($invoice->sent_at)->not->toBeNull();
    expect($invoice->flightRequest->fresh()->status)->toBe(FlightStatus::Invoiced);

    $entry = $invoice->communications()->first();
    expect($entry->type)->toBe(CommunicationType::EmailOut);
    expect($entry->to_address)->toBe('billing@customer.com');
});

it('refuses to send when the customer has no billing email on file', function () {
    Mail::fake();

    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create(['billing_email' => null]);
    $flightRequest = FlightRequest::factory()->create(['customer_id' => $customer->id]);
    Quotation::factory()->for($flightRequest)->create(['status' => QuotationStatus::Accepted]);
    $invoice = app(CreateInvoiceFromQuotation::class)($flightRequest);

    expect(fn () => app(SendInvoice::class)($invoice))->toThrow(RuntimeException::class);

    Mail::assertNothingSent();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
});
