<?php

use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\Customers\Models\Customer;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\ReferenceData\Models\Airport;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\CreateFlightRequest;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;

function makeFlightRequestForm(Customer $customer, Aircraft $aircraft): array
{
    [$origin, $destination] = Airport::query()->inRandomOrder()->take(2)->get();

    return [
        'customer_id' => $customer->id,
        'aircraft_id' => $aircraft->id,
        'callsign' => 'N650GS',
        'origin_airport_id' => $origin->id,
        'destination_airport_id' => $destination->id,
        'departure_at' => now()->addDay(),
        'arrival_at' => now()->addDay()->addHours(3),
        'passenger_count' => 6,
        'crew_count' => 2,
        'status' => FlightStatus::NewRequest->value,
    ];
}

it('lets Sales create a flight request (the spec-supported permission grant)', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create();
    $aircraft = Aircraft::factory()->for($customer)->create();

    Livewire::actingAs($sales)
        ->test(CreateFlightRequest::class)
        ->fillForm(makeFlightRequestForm($customer, $aircraft))
        ->call('create')
        ->assertHasNoFormErrors();

    $flightRequest = $company->fresh()->flightRequests()->first();

    expect($flightRequest)->not->toBeNull();
    expect($flightRequest->status)->toBe(FlightStatus::NewRequest);
    expect($flightRequest->aircraft_id)->toBe($aircraft->id);
});

it('lets Operations create a flight request', function () {
    $company = Company::factory()->create();
    $operations = userWithRoleFor($company, 'Operations');
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create();
    $aircraft = Aircraft::factory()->for($customer)->create();

    Livewire::actingAs($operations)
        ->test(CreateFlightRequest::class)
        ->fillForm(makeFlightRequestForm($customer, $aircraft))
        ->call('create')
        ->assertHasNoFormErrors();

    expect($company->fresh()->flightRequests()->count())->toBe(1);
});

it('lets a view-only role (Finance) see flight requests but not create one', function () {
    $company = Company::factory()->create();
    $finance = userWithRoleFor($company, 'Finance');

    $this->actingAs($finance)->get('/admin/flight-requests')->assertOk();
    $this->actingAs($finance)->get('/admin/flight-requests/create')->assertForbidden();
});

it('does not let a user with no role at all see flight requests', function () {
    $company = Company::factory()->create();
    $noRole = User::factory()->for($company)->create();

    $this->actingAs($noRole)->get('/admin/flight-requests')->assertForbidden();
});

it('rejects an aircraft that does not belong to the selected customer, even submitted directly', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $customerA = Customer::factory()->for($company)->create();
    $customerB = Customer::factory()->for($company)->create();
    $aircraftForB = Aircraft::factory()->for($customerB)->create();

    Livewire::actingAs($sales)
        ->test(CreateFlightRequest::class)
        ->fillForm(makeFlightRequestForm($customerA, $aircraftForB))
        ->call('create')
        ->assertHasFormErrors(['aircraft_id']);
});

it('rejects an arrival time before the departure time', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create();
    $aircraft = Aircraft::factory()->for($customer)->create();

    $data = makeFlightRequestForm($customer, $aircraft);
    $data['arrival_at'] = now()->subHour();

    Livewire::actingAs($sales)
        ->test(CreateFlightRequest::class)
        ->fillForm($data)
        ->call('create')
        ->assertHasFormErrors(['arrival_at']);
});

it('never shows one company\'s flight requests to another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CurrentCompany::class)->set($companyA->id);
    FlightRequest::factory()->create(['callsign' => 'COMPANY-A-ONLY']);

    $salesB = salesUserFor($companyB);

    $this->actingAs($salesB)
        ->get('/admin/flight-requests')
        ->assertOk()
        ->assertDontSee('COMPANY-A-ONLY');
});

it('assigns employees to a flight request', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    $operations = userWithRoleFor($company, 'Operations');
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create();
    $aircraft = Aircraft::factory()->for($customer)->create();

    $data = makeFlightRequestForm($customer, $aircraft);
    $data['assignedUsers'] = [$operations->id];

    Livewire::actingAs($sales)
        ->test(CreateFlightRequest::class)
        ->fillForm($data)
        ->call('create')
        ->assertHasNoFormErrors();

    $flightRequest = $company->fresh()->flightRequests()->first();

    expect($flightRequest->assignedUsers()->pluck('name'))->toContain($operations->name);
});

it('builds a readable display label from callsign and route', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $origin = Airport::where('icao_code', 'KJFK')->first();
    $destination = Airport::where('icao_code', 'EGLL')->first();

    $flightRequest = FlightRequest::factory()->create(['callsign' => 'N650GS']);
    $flightRequest->legs()->first()->update([
        'origin_airport_id' => $origin->id,
        'destination_airport_id' => $destination->id,
    ]);

    expect($flightRequest->fresh()->displayLabel())->toBe('N650GS (KJFK-EGLL)');
});

it('chains every leg into the route label for a multi-leg trip', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $dxb = Airport::where('icao_code', 'OMDB')->first();
    $ist = Airport::where('icao_code', 'LTFM')->first();
    $cdg = Airport::where('icao_code', 'LFPG')->first();

    $flightRequest = FlightRequest::factory()->create(['callsign' => 'N800MULTI']);
    $flightRequest->legs()->first()->update([
        'sequence' => 1,
        'origin_airport_id' => $dxb->id,
        'destination_airport_id' => $ist->id,
    ]);
    $flightRequest->legs()->create([
        'sequence' => 2,
        'origin_airport_id' => $ist->id,
        'destination_airport_id' => $cdg->id,
        'departure_at' => now()->addDays(2),
        'arrival_at' => now()->addDays(2)->addHours(4),
    ]);

    expect($flightRequest->fresh()->displayLabel())->toBe('N800MULTI (OMDB-LTFM-LFPG)');
});
