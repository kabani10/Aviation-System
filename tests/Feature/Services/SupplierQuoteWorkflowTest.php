<?php

use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Actions\RecordSupplierQuote;
use App\Domain\Services\Actions\SendSupplierRequest;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Suppliers\Models\SupplierContact;
use App\Domain\Tenancy\Models\Company;
use App\Mail\SupplierQuoteRequestMail;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;

it('sends a quote request email, logs it, and moves the service to SupplierRequestSent', function () {
    Mail::fake();

    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $supplier = Supplier::factory()->for($company)->create();
    $contact = SupplierContact::factory()->for($supplier)->create(['email' => 'quotes@fuelco.com']);
    $service = Service::factory()->for($flightRequest)->create(['supplier_id' => $supplier->id, 'status' => ServiceStatus::NotStarted]);

    app(SendSupplierRequest::class)($service, $contact, 'Please quote for tomorrow.');

    Mail::assertSent(SupplierQuoteRequestMail::class, fn ($mail) => $mail->hasTo('quotes@fuelco.com') && $mail->service->is($service));

    // Mail::assertSent() never renders the view, so it can't catch bugs in
    // the Blade template itself — render for real here. This is also a
    // regression test for a bug where the mailable's optional-note property
    // was named $message, which Illuminate\Mail\Mailer::send() silently
    // overwrites with its own Illuminate\Mail\Message view variable, crashing
    // htmlspecialchars() in the template.
    $rendered = (new SupplierQuoteRequestMail($service, 'Please quote for tomorrow.'))->render();
    expect($rendered)->toContain('Please quote for tomorrow.');

    $service->refresh();
    expect($service->status)->toBe(ServiceStatus::SupplierRequestSent);
    expect($service->quote_requested_at)->not->toBeNull();

    $entry = $service->communications()->first();
    expect($entry)->not->toBeNull();
    expect($entry->type)->toBe(CommunicationType::EmailOut);
    expect($entry->to_address)->toBe('quotes@fuelco.com');
});

it('records a supplier quote, setting cost, quote_received_at, and status', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['cost' => null, 'status' => ServiceStatus::SupplierRequestSent]);

    app(RecordSupplierQuote::class)($service, 1250.50, 'Quoted 1250.50 by phone.');

    $service->refresh();
    expect((float) $service->cost)->toBe(1250.50);
    expect($service->status)->toBe(ServiceStatus::QuotationReceived);
    expect($service->quote_received_at)->not->toBeNull();

    $entry = $service->communications()->first();
    expect($entry)->not->toBeNull();
    expect($entry->type)->toBe(CommunicationType::EmailIn);
    expect($entry->body)->toBe('Quoted 1250.50 by phone.');
});
