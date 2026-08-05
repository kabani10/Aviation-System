<?php

use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\FlightRequests\Actions\CompleteFlight;
use App\Domain\FlightRequests\Actions\MarkFlightInOperation;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\ViewFlightRequest;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;

it('marks a flight in operation, stamping the timestamp and logging it', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::Confirmed]);

    app(MarkFlightInOperation::class)($flightRequest);

    $flightRequest->refresh();
    expect($flightRequest->status)->toBe(FlightStatus::InOperation);
    expect($flightRequest->operation_started_at)->not->toBeNull();

    $entry = $flightRequest->communications()->first();
    expect($entry->type)->toBe(CommunicationType::SystemEvent);
});

it('marks a flight completed, stamping the timestamp and logging it', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::InOperation]);

    app(CompleteFlight::class)($flightRequest);

    $flightRequest->refresh();
    expect($flightRequest->status)->toBe(FlightStatus::Completed);
    expect($flightRequest->completed_at)->not->toBeNull();

    $entry = $flightRequest->communications()->first();
    expect($entry->type)->toBe(CommunicationType::SystemEvent);
});

it('offers "mark in operation" only while Confirmed, and to flights.manage holders only', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    $finance = userWithRoleFor($company, 'Finance');
    app(CurrentCompany::class)->set($company->id);

    $confirmed = FlightRequest::factory()->create(['status' => FlightStatus::Confirmed]);
    $newRequest = FlightRequest::factory()->create(['status' => FlightStatus::NewRequest]);

    Livewire::actingAs($sales)
        ->test(ViewFlightRequest::class, ['record' => $confirmed->getRouteKey()])
        ->assertActionExists('markInOperation');

    Livewire::actingAs($sales)
        ->test(ViewFlightRequest::class, ['record' => $newRequest->getRouteKey()])
        ->assertActionHidden('markInOperation');

    Livewire::actingAs($finance)
        ->test(ViewFlightRequest::class, ['record' => $confirmed->getRouteKey()])
        ->assertActionHidden('markInOperation');
});

it('offers "mark completed" only while InOperation', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $inOperation = FlightRequest::factory()->create(['status' => FlightStatus::InOperation]);
    $confirmed = FlightRequest::factory()->create(['status' => FlightStatus::Confirmed]);

    Livewire::actingAs($sales)
        ->test(ViewFlightRequest::class, ['record' => $inOperation->getRouteKey()])
        ->assertActionExists('markCompleted');

    Livewire::actingAs($sales)
        ->test(ViewFlightRequest::class, ['record' => $confirmed->getRouteKey()])
        ->assertActionHidden('markCompleted');
});

it('runs "mark in operation" end to end from the panel, showing readiness issues along the way', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::Confirmed]);

    Livewire::actingAs($sales)
        ->test(ViewFlightRequest::class, ['record' => $flightRequest->getRouteKey()])
        ->mountAction('markInOperation')
        ->assertSee('No services have been added to this flight yet.')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($flightRequest->fresh()->status)->toBe(FlightStatus::InOperation);
});
