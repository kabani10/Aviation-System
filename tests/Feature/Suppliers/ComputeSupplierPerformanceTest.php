<?php

use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Shared\Enums\ServiceType;
use App\Domain\Suppliers\Actions\ComputeSupplierPerformance;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;

it('returns null averages when the supplier has no history yet', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $supplier = Supplier::factory()->for($company)->create();

    $performance = app(ComputeSupplierPerformance::class)($supplier);

    expect($performance->servicesCount)->toBe(0);
    expect($performance->averageResponseTimeHours)->toBeNull();
    expect($performance->averageCost)->toBeNull();
    expect($performance->confirmedCount)->toBe(0);
    expect($performance->atRiskOrCancelledCount)->toBe(0);
});

it('computes average response time and cost from past services', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $supplier = Supplier::factory()->for($company)->create();
    $flightRequest = FlightRequest::factory()->create();

    Service::factory()->for($flightRequest)->create([
        'supplier_id' => $supplier->id,
        'quote_requested_at' => now()->subDays(2),
        'quote_received_at' => now()->subDays(2)->addHours(4),
        'cost' => 1000,
        'status' => ServiceStatus::Confirmed,
    ]);

    Service::factory()->for($flightRequest)->create([
        'supplier_id' => $supplier->id,
        'quote_requested_at' => now()->subDays(1),
        'quote_received_at' => now()->subDays(1)->addHours(8),
        'cost' => 2000,
        'status' => ServiceStatus::AtRisk,
    ]);

    // No response yet — shouldn't count toward the response-time average.
    Service::factory()->for($flightRequest)->create([
        'supplier_id' => $supplier->id,
        'quote_requested_at' => now(),
        'quote_received_at' => null,
        'cost' => null,
        'status' => ServiceStatus::SupplierRequestSent,
    ]);

    $performance = app(ComputeSupplierPerformance::class)($supplier);

    expect($performance->servicesCount)->toBe(3);
    expect($performance->averageResponseTimeHours)->toBe(6.0);
    expect($performance->averageCost)->toBe(1500.0);
    expect($performance->confirmedCount)->toBe(1);
    expect($performance->atRiskOrCancelledCount)->toBe(1);
});

it('filters by service type when given one', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $supplier = Supplier::factory()->for($company)->create();
    $flightRequest = FlightRequest::factory()->create();

    Service::factory()->for($flightRequest)->create(['supplier_id' => $supplier->id, 'type' => ServiceType::Fuel]);
    Service::factory()->for($flightRequest)->create(['supplier_id' => $supplier->id, 'type' => ServiceType::Catering]);

    $performance = app(ComputeSupplierPerformance::class)($supplier, ServiceType::Fuel);

    expect($performance->servicesCount)->toBe(1);
});

it('never mixes another supplier\'s services into the metrics', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $supplierA = Supplier::factory()->for($company)->create();
    $supplierB = Supplier::factory()->for($company)->create();
    $flightRequest = FlightRequest::factory()->create();

    Service::factory()->for($flightRequest)->create(['supplier_id' => $supplierA->id, 'cost' => 100]);
    Service::factory()->for($flightRequest)->create(['supplier_id' => $supplierB->id, 'cost' => 9999]);

    $performance = app(ComputeSupplierPerformance::class)($supplierA);

    expect($performance->servicesCount)->toBe(1);
    expect($performance->averageCost)->toBe(100.0);
});
