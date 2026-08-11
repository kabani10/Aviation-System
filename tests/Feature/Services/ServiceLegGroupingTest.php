<?php

use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Models\Service;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\EditFlightRequest;
use App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers\ServicesRelationManager;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;

it('groups the services table by leg by default', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $secondLeg = $flightRequest->legs()->create([
        'sequence' => 2,
        'origin_airport_id' => $flightRequest->legs->first()->destination_airport_id,
        'destination_airport_id' => $flightRequest->legs->first()->origin_airport_id,
    ]);
    Service::factory()->for($flightRequest)->create(['flight_leg_id' => $flightRequest->legs->first()->id]);
    Service::factory()->for($flightRequest)->create(['flight_leg_id' => $secondLeg->id]);

    $component = Livewire::actingAs($sales)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class]);

    expect($component->instance()->getTableGrouping()?->getColumn())->toBe('flight_leg_id');

    $component
        ->assertSee($flightRequest->legs->first()->displayLabel())
        ->assertSee($secondLeg->displayLabel());
});

it('still exposes the ordinary row actions on a grouped services table', function () {
    $company = Company::factory()->create();
    $procurement = userWithRoleFor($company, 'Procurement');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create();

    Livewire::actingAs($procurement)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionVisible('edit', $service);
});
