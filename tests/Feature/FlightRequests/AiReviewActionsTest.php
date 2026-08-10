<?php

use App\Domain\FlightRequests\Enums\RequestSource;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\EditFlightRequest;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\ViewFlightRequest;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;

it('offers "mark AI draft reviewed" on an unreviewed AI-sourced flight request', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create([
        'source' => RequestSource::Email,
        'reviewed_at' => null,
    ]);

    Livewire::actingAs($sales)
        ->test(EditFlightRequest::class, ['record' => $flightRequest->getRouteKey()])
        ->assertActionExists('markReviewed')
        ->callAction('markReviewed')
        ->assertHasNoActionErrors();

    expect($flightRequest->fresh()->reviewed_at)->not->toBeNull();
    expect($flightRequest->fresh()->assignedUsers->pluck('id'))->toContain($sales->id);
});

it('does not drop an existing assignment when confirming a draft', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    $alreadyAssigned = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create([
        'source' => RequestSource::Email,
        'reviewed_at' => null,
    ]);
    $flightRequest->assignedUsers()->attach($alreadyAssigned->id);

    Livewire::actingAs($sales)
        ->test(EditFlightRequest::class, ['record' => $flightRequest->getRouteKey()])
        ->callAction('markReviewed')
        ->assertHasNoActionErrors();

    expect($flightRequest->fresh()->assignedUsers->pluck('id'))->toContain($sales->id, $alreadyAssigned->id);
});

it('hides "mark AI draft reviewed" once already reviewed, and for manual requests entirely', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $reviewed = FlightRequest::factory()->create([
        'source' => RequestSource::Email,
        'reviewed_at' => now(),
    ]);

    Livewire::actingAs($sales)
        ->test(ViewFlightRequest::class, ['record' => $reviewed->getRouteKey()])
        ->assertActionHidden('markReviewed');

    $manual = FlightRequest::factory()->create([
        'source' => RequestSource::Manual,
    ]);

    Livewire::actingAs($sales)
        ->test(ViewFlightRequest::class, ['record' => $manual->getRouteKey()])
        ->assertActionHidden('markReviewed');
});

it('no longer offers missing information or operational risks actions on the flight request page', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['passenger_count' => null]);
    Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::AtRisk]);

    Livewire::actingAs($sales)
        ->test(ViewFlightRequest::class, ['record' => $flightRequest->getRouteKey()])
        ->assertActionDoesNotExist('missingInformation')
        ->assertActionDoesNotExist('operationalRisks');
});
