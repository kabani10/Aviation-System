<?php

use App\Domain\FlightRequests\Models\FlightLeg;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Shared\Enums\ServiceType;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\ViewFlightRequest;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;

it('shows each leg and its own services on the flight overview', function () {
    $company = Company::factory()->create();
    $admin = adminFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $leg1 = $flightRequest->legs()->first();
    $leg2 = FlightLeg::factory()->for($flightRequest)->create(['sequence' => 2]);

    $supplier = Supplier::factory()->for($company)->create(['name' => 'Acme Ground Ops']);

    Service::factory()->create([
        'flight_request_id' => $flightRequest->id,
        'flight_leg_id' => $leg1->id,
        'type' => ServiceType::GroundHandling,
        'status' => ServiceStatus::Confirmed,
        'supplier_id' => $supplier->id,
    ]);
    Service::factory()->create([
        'flight_request_id' => $flightRequest->id,
        'flight_leg_id' => $leg2->id,
        'type' => ServiceType::Fuel,
        'status' => ServiceStatus::SupplierRequestSent,
        'supplier_id' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(ViewFlightRequest::class, ['record' => $flightRequest->getRouteKey()])
        ->assertSee("Leg 1: {$leg1->originAirport->icao_code}")
        ->assertSee("Leg 2: {$leg2->originAirport->icao_code}")
        ->assertSee('Ground handling')
        ->assertSee('Fuel')
        ->assertSee('Acme Ground Ops')
        ->assertSee('Confirmed')
        ->assertSee('Supplier request sent');
});

it('says so when a leg has no services yet', function () {
    $company = Company::factory()->create();
    $admin = adminFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();

    Livewire::actingAs($admin)
        ->test(ViewFlightRequest::class, ['record' => $flightRequest->getRouteKey()])
        ->assertSee('No services added for this leg yet.');
});

it('hides cost from a role with finance.view_prices but not finance.view_costs', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company); // finance.view_prices, not finance.view_costs
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    Service::factory()->create([
        'flight_request_id' => $flightRequest->id,
        'flight_leg_id' => $flightRequest->legs()->first()->id,
        'cost' => 1234.56,
        'selling_price' => 2345.67,
    ]);

    Livewire::actingAs($sales)
        ->test(ViewFlightRequest::class, ['record' => $flightRequest->getRouteKey()])
        ->assertDontSee('1,234.56')
        ->assertSee('2,345.67');
});

it('hides selling price from a role with finance.view_costs but not finance.view_prices', function () {
    $company = Company::factory()->create();
    $procurement = userWithRoleFor($company, 'Procurement'); // finance.view_costs, not finance.view_prices
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    Service::factory()->create([
        'flight_request_id' => $flightRequest->id,
        'flight_leg_id' => $flightRequest->legs()->first()->id,
        'cost' => 1234.56,
        'selling_price' => 2345.67,
    ]);

    Livewire::actingAs($procurement)
        ->test(ViewFlightRequest::class, ['record' => $flightRequest->getRouteKey()])
        ->assertSee('1,234.56')
        ->assertDontSee('2,345.67');
});
