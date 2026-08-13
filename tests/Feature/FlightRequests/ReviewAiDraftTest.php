<?php

use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Customers\Models\Customer;
use App\Domain\FlightRequests\Enums\RequestSource;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\ListFlightRequests;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\ReviewFlightRequest;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;

it('shows "Review draft" only for a flight request that needs review', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $needsReview = FlightRequest::factory()->create(['source' => RequestSource::Email, 'reviewed_at' => null]);
    $alreadyReviewed = FlightRequest::factory()->create(['source' => RequestSource::Email, 'reviewed_at' => now()]);
    $manual = FlightRequest::factory()->create(['source' => RequestSource::Manual]);

    Livewire::actingAs($sales)
        ->test(ListFlightRequests::class)
        ->assertTableActionVisible('review', $needsReview)
        ->assertTableActionHidden('review', $alreadyReviewed)
        ->assertTableActionHidden('review', $manual);
});

it('hides "Review draft" from a view-only role even for a flight request that needs review', function () {
    $company = Company::factory()->create();
    $finance = userWithRoleFor($company, 'Finance');
    app(CurrentCompany::class)->set($company->id);

    $needsReview = FlightRequest::factory()->create(['source' => RequestSource::Email, 'reviewed_at' => null]);

    Livewire::actingAs($finance)
        ->test(ListFlightRequests::class)
        ->assertTableActionHidden('review', $needsReview);
});

it('renders the drafted fields and the source email side by side on the review page', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create([
        'source' => RequestSource::Email,
        'reviewed_at' => null,
        'callsign' => 'N650GS',
    ]);

    $email = (new LogCommunication)(
        communicable: $flightRequest,
        type: CommunicationType::EmailIn,
        body: 'We need handling for our flight tomorrow morning.',
        subject: 'Handling request for N650GS',
        fromAddress: 'ops@customer-airline.com',
    );

    Livewire::actingAs($sales)
        ->test(ReviewFlightRequest::class, ['record' => $flightRequest->getRouteKey()])
        ->assertFormSet(['callsign' => 'N650GS'])
        ->assertSee($email->subject)
        ->assertSee($email->body)
        ->assertSee('ops@customer-airline.com');
});

it('shows the earliest inbound email as the source, not a later reply', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['source' => RequestSource::Email, 'reviewed_at' => null]);

    $original = (new LogCommunication)(
        communicable: $flightRequest,
        type: CommunicationType::EmailIn,
        body: 'Original request body.',
        subject: 'Original request',
        occurredAt: now()->subDays(2),
    );

    (new LogCommunication)(
        communicable: $flightRequest,
        type: CommunicationType::EmailIn,
        body: 'A later follow-up email.',
        subject: 'Re: Original request',
        occurredAt: now(),
    );

    expect(
        Livewire::actingAs($sales)
            ->test(ReviewFlightRequest::class, ['record' => $flightRequest->getRouteKey()])
            ->instance()
            ->getSourceEmail()
            ->is($original)
    )->toBeTrue();
});

it('confirms the draft from the review page the same way EditFlightRequest does', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create([
        'source' => RequestSource::Email,
        'reviewed_at' => null,
    ]);

    Livewire::actingAs($sales)
        ->test(ReviewFlightRequest::class, ['record' => $flightRequest->getRouteKey()])
        ->assertActionExists('markReviewed')
        ->callAction('markReviewed')
        ->assertHasNoActionErrors();

    expect($flightRequest->fresh()->reviewed_at)->not->toBeNull();
    expect($flightRequest->fresh()->assignedUsers->pluck('id'))->toContain($sales->id);
});

it('creates and selects a customer, then an aircraft for that customer, without leaving the review page', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create([
        'source' => RequestSource::Email,
        'reviewed_at' => null,
        'customer_id' => null,
        'aircraft_id' => null,
    ]);

    $component = Livewire::actingAs($sales)
        ->test(ReviewFlightRequest::class, ['record' => $flightRequest->getRouteKey()])
        ->callFormComponentAction('customer_id', 'createOption', data: [
            'name' => 'New Charter Broker LLC',
            'billing_email' => 'ops@newcharterbroker.example',
        ]);

    $customer = Customer::query()->where('name', 'New Charter Broker LLC')->sole();
    expect($customer->company_id)->toBe($company->id);
    expect($customer->billing_email)->toBe('ops@newcharterbroker.example');
    $component->assertFormSet(['customer_id' => $customer->id]);

    $component->callFormComponentAction('aircraft_id', 'createOption', data: [
        'registration' => 'N999XX',
        'aircraft_type' => 'Citation X',
    ]);

    $aircraft = Aircraft::query()->where('registration', 'N999XX')->sole();
    expect($aircraft->customer_id)->toBe($customer->id);
    expect($aircraft->aircraft_type)->toBe('Citation X');
    $component->assertFormSet(['aircraft_id' => $aircraft->id]);
});

it('blocks a view-only role from opening the review page at all', function () {
    $company = Company::factory()->create();
    $finance = userWithRoleFor($company, 'Finance');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['source' => RequestSource::Email, 'reviewed_at' => null]);

    $this->actingAs($finance)
        ->get("/admin/flight-requests/{$flightRequest->getRouteKey()}/review")
        ->assertForbidden();
});
