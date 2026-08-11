<?php

use App\AI\SupplierReplyExtraction\Jobs\ExtractSupplierReplyFromEmail;
use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Communications\Models\Communication;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Actions\MatchSupplierReplyToInquiry;
use App\Domain\Services\Enums\SupplierInquiryStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Services\Models\SupplierInquiry;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Suppliers\Models\SupplierContact;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Http;

// Set explicitly rather than trusting .env — same reasoning as
// RequestExtractionTest's beforeEach: CI's .env.example leaves this blank.
beforeEach(fn () => config(['services.anthropic.key' => 'test-anthropic-key']));

function fakeClaudeReplyExtraction(?float $cost): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_test', 'name' => 'extract_supplier_reply', 'input' => ['cost' => $cost]],
            ],
            'stop_reason' => 'tool_use',
        ]),
    ]);
}

function openInquiryFor(Company $company, string $fromAddress): SupplierInquiry
{
    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create();
    $supplier = Supplier::factory()->for($company)->create();
    $contact = SupplierContact::factory()->for($supplier)->create(['email' => $fromAddress]);

    return $service->supplierInquiries()->create([
        'supplier_id' => $supplier->id,
        'supplier_contact_id' => $contact->id,
        'status' => SupplierInquiryStatus::Sent,
        'requested_at' => now(),
    ]);
}

function inboundReplyFor(Company $company, string $fromAddress, string $body): Communication
{
    return (new LogCommunication)(
        communicable: $company,
        type: CommunicationType::EmailIn,
        body: $body,
        subject: 'Re: Quote request',
        fromAddress: $fromAddress,
    );
}

it('records the extracted price and moves the email onto the matched inquiry', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $inquiry = openInquiryFor($company, 'quotes@fuelco.com');
    $communication = inboundReplyFor($company, 'quotes@fuelco.com', 'Sure, we can do this for $850 all-in.');

    fakeClaudeReplyExtraction(850.0);

    ExtractSupplierReplyFromEmail::dispatchSync($communication);

    $inquiry->refresh();
    expect($inquiry->status)->toBe(SupplierInquiryStatus::QuoteReceived);
    expect((float) $inquiry->cost)->toBe(850.0);
    expect($inquiry->responded_at)->not->toBeNull();

    $communication->refresh();
    expect($communication->communicable_type)->toBe(SupplierInquiry::class);
    expect($communication->communicable_id)->toBe($inquiry->id);

    // The real email became the inquiry's own timeline entry — no second,
    // synthetic "quote received" Communication alongside it.
    expect($inquiry->communications()->count())->toBe(1);
});

it('leaves the inquiry and email untouched when Claude finds no clear price', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $inquiry = openInquiryFor($company, 'quotes@fuelco.com');
    $communication = inboundReplyFor($company, 'quotes@fuelco.com', 'Can you confirm the passenger count first?');

    fakeClaudeReplyExtraction(null);

    ExtractSupplierReplyFromEmail::dispatchSync($communication);

    $inquiry->refresh();
    expect($inquiry->status)->toBe(SupplierInquiryStatus::Sent);
    expect($inquiry->cost)->toBeNull();

    $communication->refresh();
    expect($communication->communicable_type)->toBe(Company::class);
});

it('never calls Claude when the sender does not match any supplier contact', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    openInquiryFor($company, 'quotes@fuelco.com');
    $communication = inboundReplyFor($company, 'someone-else@example.com', 'Hello, requesting a flight.');

    Http::fake();

    ExtractSupplierReplyFromEmail::dispatchSync($communication);

    Http::assertNothingSent();
});

it('never calls Claude when the contact has more than one open inquiry — too ambiguous to guess', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $supplier = Supplier::factory()->for($company)->create();
    $contact = SupplierContact::factory()->for($supplier)->create(['email' => 'quotes@fuelco.com']);

    $serviceA = Service::factory()->for($flightRequest)->create();
    $serviceB = Service::factory()->for($flightRequest)->create();
    $serviceA->supplierInquiries()->create(['supplier_id' => $supplier->id, 'supplier_contact_id' => $contact->id, 'status' => SupplierInquiryStatus::Sent, 'requested_at' => now()]);
    $serviceB->supplierInquiries()->create(['supplier_id' => $supplier->id, 'supplier_contact_id' => $contact->id, 'status' => SupplierInquiryStatus::Sent, 'requested_at' => now()]);

    $communication = inboundReplyFor($company, 'quotes@fuelco.com', 'It will be $500.');

    Http::fake();

    ExtractSupplierReplyFromEmail::dispatchSync($communication);

    Http::assertNothingSent();
});

it('never calls Claude when the contact has no open (Sent) inquiries left', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $inquiry = openInquiryFor($company, 'quotes@fuelco.com');
    $inquiry->update(['status' => SupplierInquiryStatus::QuoteReceived, 'cost' => 500]);

    $communication = inboundReplyFor($company, 'quotes@fuelco.com', 'Following up on my earlier quote.');

    Http::fake();

    ExtractSupplierReplyFromEmail::dispatchSync($communication);

    Http::assertNothingSent();
    expect($communication->fresh()->communicable_type)->toBe(Company::class);
});

it('swallows a Claude API failure without throwing or touching the inquiry', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $inquiry = openInquiryFor($company, 'quotes@fuelco.com');
    $communication = inboundReplyFor($company, 'quotes@fuelco.com', 'Price is $500.');

    Http::fake(['api.anthropic.com/*' => Http::response('Service unavailable', 503)]);

    ExtractSupplierReplyFromEmail::dispatchSync($communication);

    expect($inquiry->fresh()->status)->toBe(SupplierInquiryStatus::Sent);
    expect($communication->fresh()->communicable_type)->toBe(Company::class);
});

it('matches case-insensitively on the sender address', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $inquiry = openInquiryFor($company, 'quotes@fuelco.com');
    $communication = inboundReplyFor($company, 'Quotes@FuelCo.com', 'It will be $500.');

    fakeClaudeReplyExtraction(500.0);

    ExtractSupplierReplyFromEmail::dispatchSync($communication);

    expect($inquiry->fresh()->cost)->not->toBeNull();
});

it('matches exactly one open inquiry directly via MatchSupplierReplyToInquiry', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $inquiry = openInquiryFor($company, 'quotes@fuelco.com');
    $communication = inboundReplyFor($company, 'quotes@fuelco.com', 'irrelevant body');

    $match = app(MatchSupplierReplyToInquiry::class)($communication);

    expect($match)->not->toBeNull();
    expect($match->is($inquiry))->toBeTrue();
});

it('returns null from MatchSupplierReplyToInquiry when the email has no from_address', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $communication = (new LogCommunication)(
        communicable: $company,
        type: CommunicationType::Note,
        body: 'A manually logged note, no sender address.',
    );

    expect(app(MatchSupplierReplyToInquiry::class)($communication))->toBeNull();
});
