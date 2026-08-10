<?php

use App\Domain\FlightRequests\Models\FlightLeg;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\ReferenceData\Models\Airport;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Shared\Enums\ServiceType;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\EditFlightRequest;
use App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers\LegsRelationManager;
use App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers\ServicesRelationManager;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;

it('lets flights.manage holders add a second leg to a trip', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    [$origin, $destination] = Airport::query()->inRandomOrder()->take(2)->get();

    Livewire::actingAs($sales)
        ->test(LegsRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->callTableAction('create', data: [
            'sequence' => 2,
            'origin_airport_id' => $origin->id,
            'destination_airport_id' => $destination->id,
            'departure_at' => now()->addDays(3),
            'arrival_at' => now()->addDays(3)->addHours(5),
        ])
        ->assertHasNoTableActionErrors();

    expect($flightRequest->legs()->count())->toBe(2);
});

it('does not let a role without flights.manage add a leg', function () {
    $company = Company::factory()->create();
    $finance = userWithRoleFor($company, 'Finance');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();

    Livewire::actingAs($finance)
        ->test(LegsRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionHidden('create');
});

it('refuses to delete the only remaining leg', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $onlyLeg = $flightRequest->legs()->first();

    Livewire::actingAs($sales)
        ->test(LegsRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionHidden('delete', $onlyLeg);
});

it('refuses to delete a leg that already has services on it, even if other legs exist', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $legWithServices = $flightRequest->legs()->first();
    Service::factory()->create(['flight_request_id' => $flightRequest->id, 'flight_leg_id' => $legWithServices->id]);
    FlightLeg::factory()->for($flightRequest)->create(['sequence' => 2]);

    Livewire::actingAs($sales)
        ->test(LegsRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionHidden('delete', $legWithServices);
});

it('allows deleting an empty, non-last leg', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $secondLeg = FlightLeg::factory()->for($flightRequest)->create(['sequence' => 2]);

    Livewire::actingAs($sales)
        ->test(LegsRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->callTableAction('delete', $secondLeg)
        ->assertHasNoTableActionErrors();

    expect($flightRequest->legs()->count())->toBe(1);
});

it('never shows one company\'s legs to another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CurrentCompany::class)->set($companyA->id);
    $flightA = FlightRequest::factory()->create();
    $legA = $flightA->legs()->first();

    app(CurrentCompany::class)->set($companyB->id);
    expect(FlightLeg::query()->pluck('id'))->not->toContain($legA->id);
});

it('assigns a service to a specific leg, and offers only this flight\'s own legs', function () {
    $company = Company::factory()->create();
    $operations = userWithRoleFor($company, 'Operations');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $secondLeg = FlightLeg::factory()->for($flightRequest)->create(['sequence' => 2]);

    Livewire::actingAs($operations)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->callTableAction('create', data: [
            'flight_leg_id' => $secondLeg->id,
            'type' => ServiceType::GroundHandling->value,
            'status' => ServiceStatus::NotStarted->value,
        ])
        ->assertHasNoTableActionErrors();

    $service = $flightRequest->services()->first();
    expect($service->flight_leg_id)->toBe($secondLeg->id);
});

it('rejects a leg id that does not belong to this flight, even submitted directly', function () {
    $company = Company::factory()->create();
    $operations = userWithRoleFor($company, 'Operations');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $otherFlightsLeg = FlightRequest::factory()->create()->legs()->first();

    Livewire::actingAs($operations)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->callTableAction('create', data: [
            'flight_leg_id' => $otherFlightsLeg->id,
            'type' => ServiceType::GroundHandling->value,
            'status' => ServiceStatus::NotStarted->value,
        ])
        ->assertHasTableActionErrors(['flight_leg_id']);
});

it('every factory-made flight request has exactly one leg by default', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();

    expect($flightRequest->legs)->toHaveCount(1);
    expect($flightRequest->legs->first()->sequence)->toBe(1);
});

it('lets a service factory keep working against a flight request directly, resolving its leg automatically', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create();

    expect($service->flight_leg_id)->toBe($flightRequest->legs()->first()->id);
});
