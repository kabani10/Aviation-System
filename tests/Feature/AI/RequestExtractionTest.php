<?php

use App\AI\RequestExtraction\Jobs\ExtractFlightRequestFromEmail;
use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Communications\Models\Communication;
use App\Domain\Customers\Models\Customer;
use App\Domain\FlightRequests\Enums\RequestSource;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\ReferenceData\Models\Airport;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Set explicitly rather than trusting whatever's in .env — same reasoning
// as PostmarkInboundWebhookTest's inbound_secret: CI copies .env.example,
// which leaves ANTHROPIC_API_KEY blank, so reading the real value would
// make these tests pass locally and silently no-op in CI.
beforeEach(fn () => config(['services.anthropic.key' => 'test-anthropic-key']));

function fakeClaudeExtraction(array $toolInput): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => 'Extracted the flight details.'],
                ['type' => 'tool_use', 'id' => 'toolu_test', 'name' => 'extract_flight_request', 'input' => $toolInput],
            ],
            'stop_reason' => 'tool_use',
        ]),
    ]);
}

function inboundCommunicationFor(Company $company, string $subject, string $body): Communication
{
    return (new LogCommunication)(
        communicable: $company,
        type: CommunicationType::EmailIn,
        body: $body,
        subject: $subject,
        fromAddress: 'ops@customer-airline.com',
    );
}

it('creates a draft flight request from a confident extraction and moves the Communication onto it', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create();
    $aircraft = Aircraft::factory()->for($customer)->create();
    $origin = Airport::where('icao_code', 'KJFK')->first();
    $destination = Airport::where('icao_code', 'EGLL')->first();

    $communication = inboundCommunicationFor($company, 'Handling request', 'We need handling for our flight tomorrow.');

    fakeClaudeExtraction([
        'customer_id' => $customer->id,
        'aircraft_id' => $aircraft->id,
        'callsign' => 'N650GS',
        'origin_airport_code' => 'KJFK',
        'destination_airport_code' => 'EGLL',
        'departure_at' => '2026-09-01T09:00:00Z',
        'arrival_at' => '2026-09-01T18:00:00Z',
        'passenger_count' => 4,
        'crew_count' => 2,
        'requested_services_summary' => 'Fuel and ground handling',
        'special_instructions' => null,
        'unclear_points' => [],
    ]);

    ExtractFlightRequestFromEmail::dispatchSync($communication);

    $flightRequest = $customer->flightRequests()->first();

    expect($flightRequest)->not->toBeNull();
    expect($flightRequest->source)->toBe(RequestSource::Email);
    expect($flightRequest->reviewed_at)->toBeNull();
    expect($flightRequest->aircraft_id)->toBe($aircraft->id);
    expect($flightRequest->origin_airport_id)->toBe($origin->id);
    expect($flightRequest->destination_airport_id)->toBe($destination->id);
    expect($flightRequest->needsReview())->toBeTrue();

    $communication->refresh();
    expect($communication->communicable_type)->toBe(FlightRequest::class);
    expect($communication->communicable_id)->toBe($flightRequest->id);
});

it('leaves the Communication on the Company and stashes the extraction when the match is not confident', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $communication = inboundCommunicationFor($company, 'New customer inquiry', 'Hi, we would like to charter a flight but never worked with you before.');

    fakeClaudeExtraction([
        'customer_id' => null,
        'aircraft_id' => null,
        'callsign' => null,
        'origin_airport_code' => null,
        'destination_airport_code' => null,
        'departure_at' => null,
        'arrival_at' => null,
        'passenger_count' => null,
        'crew_count' => null,
        'requested_services_summary' => 'A charter flight, details unclear',
        'special_instructions' => null,
        'unclear_points' => ['Sender is not an existing customer', 'No route or dates given'],
    ]);

    ExtractFlightRequestFromEmail::dispatchSync($communication);

    expect($company->flightRequests()->count())->toBe(0);

    $communication->refresh();
    expect($communication->communicable_type)->toBe(Company::class);
    expect($communication->metadata['ai_extraction']['unclear_points'])->toContain('Sender is not an existing customer');
});

it('does nothing when the Claude API key is not configured, leaving the Communication untouched', function () {
    config(['services.anthropic.key' => null]);

    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $communication = inboundCommunicationFor($company, 'Handling request', 'We need fuel for tomorrow.');

    ExtractFlightRequestFromEmail::dispatchSync($communication);

    expect($company->flightRequests()->count())->toBe(0);

    $communication->refresh();
    expect($communication->communicable_type)->toBe(Company::class);
});

it('only offers the current company\'s own customers as extraction context, not another tenant\'s', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CurrentCompany::class)->set($companyA->id);
    Customer::factory()->for($companyA)->create(['name' => 'Visible Customer A']);

    app(CurrentCompany::class)->set($companyB->id);
    Customer::factory()->for($companyB)->create(['name' => 'Hidden Customer B']);

    $communication = inboundCommunicationFor($companyB, 'Request', 'Body text.');

    fakeClaudeExtraction([
        'customer_id' => null,
        'aircraft_id' => null,
        'callsign' => null,
        'origin_airport_code' => null,
        'destination_airport_code' => null,
        'departure_at' => null,
        'arrival_at' => null,
        'passenger_count' => null,
        'crew_count' => null,
        'requested_services_summary' => null,
        'special_instructions' => null,
        'unclear_points' => [],
    ]);

    ExtractFlightRequestFromEmail::dispatchSync($communication);

    Http::assertSent(function (Request $request): bool {
        $body = $request->body();

        return str_contains($body, 'Hidden Customer B') && ! str_contains($body, 'Visible Customer A');
    });
});
