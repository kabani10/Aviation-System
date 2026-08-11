<?php

use App\AI\SupplierConfirmationExtraction\Jobs\ExtractSupplierConfirmationFromEmail;
use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Communications\Models\Communication;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Actions\MatchSupplierConfirmationReplyToInquiry;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Enums\SupplierInquiryStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Services\Models\SupplierInquiry;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Suppliers\Models\SupplierContact;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['services.anthropic.key' => 'test-anthropic-key']));

function fakeClaudeConfirmationExtraction(bool $confirmed): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_test', 'name' => 'extract_supplier_confirmation', 'input' => ['confirmed' => $confirmed]],
            ],
            'stop_reason' => 'tool_use',
        ]),
    ]);
}

function awaitingConfirmationInquiryFor(Company $company, string $fromAddress): SupplierInquiry
{
    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::QuotationReceived]);
    $supplier = Supplier::factory()->for($company)->create();
    $contact = SupplierContact::factory()->for($supplier)->create(['email' => $fromAddress]);

    return $service->supplierInquiries()->create([
        'supplier_id' => $supplier->id,
        'supplier_contact_id' => $contact->id,
        'status' => SupplierInquiryStatus::Chosen,
        'cost' => 900,
        'confirmation_requested_at' => now()->subHour(),
    ]);
}

function inboundConfirmationReplyFor(Company $company, string $fromAddress, string $body): Communication
{
    return (new LogCommunication)(
        communicable: $company,
        type: CommunicationType::EmailIn,
        body: $body,
        subject: 'Re: Booking confirmation',
        fromAddress: $fromAddress,
    );
}

it('confirms the inquiry and the Service when Claude clearly detects a confirmation', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $inquiry = awaitingConfirmationInquiryFor($company, 'quotes@fuelco.com');
    $communication = inboundConfirmationReplyFor($company, 'quotes@fuelco.com', 'Confirmed, we will be there.');

    fakeClaudeConfirmationExtraction(true);

    ExtractSupplierConfirmationFromEmail::dispatchSync($communication);

    $inquiry->refresh();
    expect($inquiry->status)->toBe(SupplierInquiryStatus::Confirmed);
    expect($inquiry->confirmed_at)->not->toBeNull();

    $service = $inquiry->service->fresh();
    expect($service->status)->toBe(ServiceStatus::Confirmed);
    expect($service->supplier_confirmed_at)->not->toBeNull();

    $communication->refresh();
    expect($communication->communicable_type)->toBe(SupplierInquiry::class);
    expect($communication->communicable_id)->toBe($inquiry->id);
});

it('leaves the inquiry untouched when Claude does not detect a clear confirmation', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $inquiry = awaitingConfirmationInquiryFor($company, 'quotes@fuelco.com');
    $communication = inboundConfirmationReplyFor($company, 'quotes@fuelco.com', 'Can we revisit the price first?');

    fakeClaudeConfirmationExtraction(false);

    ExtractSupplierConfirmationFromEmail::dispatchSync($communication);

    $inquiry->refresh();
    expect($inquiry->status)->toBe(SupplierInquiryStatus::Chosen);
    expect($inquiry->confirmed_at)->toBeNull();
    expect($communication->fresh()->communicable_type)->toBe(Company::class);
});

it('never calls Claude for a sender with no inquiry awaiting confirmation', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    // An inquiry exists, but no confirmation has been requested yet.
    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create();
    $supplier = Supplier::factory()->for($company)->create();
    $contact = SupplierContact::factory()->for($supplier)->create(['email' => 'quotes@fuelco.com']);
    $service->supplierInquiries()->create([
        'supplier_id' => $supplier->id,
        'supplier_contact_id' => $contact->id,
        'status' => SupplierInquiryStatus::Chosen,
    ]);

    $communication = inboundConfirmationReplyFor($company, 'quotes@fuelco.com', 'Confirmed.');

    Http::fake();

    ExtractSupplierConfirmationFromEmail::dispatchSync($communication);

    Http::assertNothingSent();
});

it('matches directly via MatchSupplierConfirmationReplyToInquiry', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $inquiry = awaitingConfirmationInquiryFor($company, 'quotes@fuelco.com');
    $communication = inboundConfirmationReplyFor($company, 'quotes@fuelco.com', 'irrelevant');

    $match = app(MatchSupplierConfirmationReplyToInquiry::class)($communication);

    expect($match?->is($inquiry))->toBeTrue();
});
