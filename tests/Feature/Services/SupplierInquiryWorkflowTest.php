<?php

use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Actions\ChooseSupplierInquiry;
use App\Domain\Services\Actions\RecordSupplierInquiryResponse;
use App\Domain\Services\Actions\SendSupplierInquiry;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Enums\SupplierInquiryStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Suppliers\Models\SupplierContact;
use App\Domain\Tenancy\Models\Company;
use App\Mail\SupplierQuoteRequestMail;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;

it('sends a quote request email, creates a SupplierInquiry, and moves the service to SupplierRequestSent', function () {
    Mail::fake();

    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $supplier = Supplier::factory()->for($company)->create();
    $contact = SupplierContact::factory()->for($supplier)->create(['email' => 'quotes@fuelco.com']);
    $service = Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::NotStarted]);

    $inquiry = app(SendSupplierInquiry::class)($service, $supplier, $contact, 'Please quote for tomorrow.');

    Mail::assertSent(SupplierQuoteRequestMail::class, fn ($mail) => $mail->hasTo('quotes@fuelco.com') && $mail->service->is($service));

    expect($inquiry->status)->toBe(SupplierInquiryStatus::Sent);
    expect($inquiry->supplier_id)->toBe($supplier->id);
    expect($inquiry->requested_at)->not->toBeNull();

    $service->refresh();
    expect($service->status)->toBe(ServiceStatus::SupplierRequestSent);
    // Sending doesn't touch supplier_id/cost directly anymore — those mean
    // "the supplier we chose", not "the supplier we're asking".
    expect($service->supplier_id)->toBeNull();

    $entry = $inquiry->communications()->first();
    expect($entry)->not->toBeNull();
    expect($entry->type)->toBe(CommunicationType::EmailOut);
    expect($entry->to_address)->toBe('quotes@fuelco.com');
});

it('lets a service have several inquiries out at once, to different suppliers', function () {
    Mail::fake();

    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::NotStarted]);
    $supplierA = Supplier::factory()->for($company)->create();
    $contactA = SupplierContact::factory()->for($supplierA)->create();
    $supplierB = Supplier::factory()->for($company)->create();
    $contactB = SupplierContact::factory()->for($supplierB)->create();

    app(SendSupplierInquiry::class)($service, $supplierA, $contactA);
    app(SendSupplierInquiry::class)($service, $supplierB, $contactB);

    expect($service->supplierInquiries()->count())->toBe(2);
    // The second inquiry doesn't re-trigger the NotStarted -> SupplierRequestSent
    // jump (it already happened), but it definitely shouldn't error or regress it.
    expect($service->fresh()->status)->toBe(ServiceStatus::SupplierRequestSent);
});

it('does not regress a service past SupplierRequestSent when a later inquiry is sent', function () {
    Mail::fake();

    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::WaitingCustomerApproval]);
    $supplier = Supplier::factory()->for($company)->create();
    $contact = SupplierContact::factory()->for($supplier)->create();

    app(SendSupplierInquiry::class)($service, $supplier, $contact);

    expect($service->fresh()->status)->toBe(ServiceStatus::WaitingCustomerApproval);
});

it('records a supplier inquiry response, setting cost, responded_at, and status — without touching the Service', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['supplier_id' => null, 'cost' => null]);
    $supplier = Supplier::factory()->for($company)->create();
    $inquiry = $service->supplierInquiries()->create([
        'supplier_id' => $supplier->id,
        'status' => SupplierInquiryStatus::Sent,
        'requested_at' => now(),
    ]);

    app(RecordSupplierInquiryResponse::class)($inquiry, 1250.50, 'Quoted 1250.50 by phone.');

    $inquiry->refresh();
    expect((float) $inquiry->cost)->toBe(1250.50);
    expect($inquiry->status)->toBe(SupplierInquiryStatus::QuoteReceived);
    expect($inquiry->responded_at)->not->toBeNull();

    expect($service->fresh()->cost)->toBeNull();
    expect($service->fresh()->supplier_id)->toBeNull();

    $entry = $inquiry->communications()->first();
    expect($entry)->not->toBeNull();
    expect($entry->type)->toBe(CommunicationType::EmailIn);
    expect($entry->body)->toBe('Quoted 1250.50 by phone.');
});

it('choosing an inquiry copies its supplier and cost onto the Service and advances status', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::SupplierRequestSent, 'supplier_id' => null, 'cost' => null]);
    $supplier = Supplier::factory()->for($company)->create();
    $inquiry = $service->supplierInquiries()->create([
        'supplier_id' => $supplier->id,
        'status' => SupplierInquiryStatus::QuoteReceived,
        'cost' => 900,
        'requested_at' => now()->subHour(),
        'responded_at' => now(),
    ]);

    app(ChooseSupplierInquiry::class)($inquiry);

    $service->refresh();
    expect($service->supplier_id)->toBe($supplier->id);
    expect((float) $service->cost)->toBe(900.0);
    expect($service->status)->toBe(ServiceStatus::QuotationReceived);
    expect($inquiry->fresh()->status)->toBe(SupplierInquiryStatus::Chosen);
});

it('does not regress a service already past QuotationReceived when a different inquiry is chosen', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::Confirmed]);
    $supplier = Supplier::factory()->for($company)->create();
    $inquiry = $service->supplierInquiries()->create([
        'supplier_id' => $supplier->id,
        'status' => SupplierInquiryStatus::QuoteReceived,
        'cost' => 500,
    ]);

    app(ChooseSupplierInquiry::class)($inquiry);

    // Price still updates — the operator explicitly chose a new number —
    // but a status reflecting real progress isn't silently rewound.
    expect((float) $service->fresh()->cost)->toBe(500.0);
    expect($service->fresh()->status)->toBe(ServiceStatus::Confirmed);
});

it('demotes the previously chosen inquiry back to QuoteReceived when a different one is chosen instead', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::SupplierRequestSent]);
    $firstSupplier = Supplier::factory()->for($company)->create();
    $secondSupplier = Supplier::factory()->for($company)->create();

    $firstChoice = $service->supplierInquiries()->create([
        'supplier_id' => $firstSupplier->id,
        'status' => SupplierInquiryStatus::QuoteReceived,
        'cost' => 700,
    ]);
    $secondChoice = $service->supplierInquiries()->create([
        'supplier_id' => $secondSupplier->id,
        'status' => SupplierInquiryStatus::QuoteReceived,
        'cost' => 650,
    ]);

    app(ChooseSupplierInquiry::class)($firstChoice);
    expect($firstChoice->fresh()->status)->toBe(SupplierInquiryStatus::Chosen);

    app(ChooseSupplierInquiry::class)($secondChoice);

    expect($secondChoice->fresh()->status)->toBe(SupplierInquiryStatus::Chosen);
    expect($firstChoice->fresh()->status)->toBe(SupplierInquiryStatus::QuoteReceived);
    expect($service->fresh()->supplier_id)->toBe($secondSupplier->id);
    expect((float) $service->fresh()->cost)->toBe(650.0);
});
