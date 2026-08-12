<?php

use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\ListFlightRequests;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\ViewFlightRequest;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;

it('sets the readiness_warning column true for an unready flight departing soon', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::QuotationInProgress]);
    $flightRequest->legs->first()->update(['departure_at' => now()->addHours(3)]);
    Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::QuotationReceived]);

    Livewire::actingAs($sales)
        ->test(ListFlightRequests::class)
        ->assertTableColumnStateSet('readiness_warning', true, $flightRequest);
});

it('sets the readiness_warning column false for a flight departing far in the future', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::QuotationInProgress]);
    $flightRequest->legs->first()->update(['departure_at' => now()->addDays(10)]);
    Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::QuotationReceived]);

    Livewire::actingAs($sales)
        ->test(ListFlightRequests::class)
        ->assertTableColumnStateSet('readiness_warning', false, $flightRequest);
});

it('shows the readiness warning icon on an unready flight\'s kanban card', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::QuotationInProgress]);
    $flightRequest->legs->first()->update(['departure_at' => now()->addHours(3)]);
    Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::QuotationReceived]);

    Livewire::actingAs($sales)
        ->test(ListFlightRequests::class)
        ->set('displayMode', 'kanban')
        ->assertSee('Departing soon and not fully ready');
});

it('does not show the readiness warning on a fully ready flight\'s kanban card', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::QuotationInProgress]);
    $flightRequest->legs->first()->update(['departure_at' => now()->addDays(10)]);
    Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::QuotationReceived]);

    Livewire::actingAs($sales)
        ->test(ListFlightRequests::class)
        ->set('displayMode', 'kanban')
        ->assertDontSee('Departing soon and not fully ready');
});

it('shows the readiness warning banner with its specific issues on the view page', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::QuotationInProgress]);
    $flightRequest->legs->first()->update(['departure_at' => now()->addHours(3)]);
    Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::QuotationReceived]);

    Livewire::actingAs($sales)
        ->test(ViewFlightRequest::class, ['record' => $flightRequest->getRouteKey()])
        ->assertSee('Departing soon and not fully ready')
        ->assertSee('No quotation has been accepted for this flight yet.');
});

it('does not show the readiness warning banner on a fully ready flight\'s view page', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::Confirmed]);
    $flightRequest->legs->first()->update(['departure_at' => now()->addHours(3)]);
    Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::Confirmed]);
    Quotation::factory()->for($flightRequest)->create(['status' => QuotationStatus::Accepted]);

    Livewire::actingAs($sales)
        ->test(ViewFlightRequest::class, ['record' => $flightRequest->getRouteKey()])
        ->assertDontSee('Departing soon and not fully ready');
});
