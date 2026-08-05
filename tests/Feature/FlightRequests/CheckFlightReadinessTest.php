<?php

use App\Domain\FlightRequests\Actions\CheckFlightReadiness;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;

it('flags a flight with no services at all', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();

    $issues = app(CheckFlightReadiness::class)($flightRequest);

    expect($issues->pluck('field'))->toContain('services');
});

it('flags a service that has not been confirmed yet', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::QuotationReceived]);

    $issues = app(CheckFlightReadiness::class)($flightRequest);

    expect($issues->pluck('field'))->toContain("services.{$service->id}.status");
});

it('does not flag a confirmed or completed service', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $confirmed = Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::Confirmed]);
    $completed = Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::Completed]);

    $issues = app(CheckFlightReadiness::class)($flightRequest);

    expect($issues->pluck('field'))->not->toContain("services.{$confirmed->id}.status");
    expect($issues->pluck('field'))->not->toContain("services.{$completed->id}.status");
});

it('does not flag a cancelled service', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $cancelled = Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::Cancelled]);

    $issues = app(CheckFlightReadiness::class)($flightRequest);

    expect($issues->pluck('field'))->not->toContain("services.{$cancelled->id}.status");
});

it('flags a flight with no accepted quotation', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::Confirmed]);

    $issues = app(CheckFlightReadiness::class)($flightRequest);

    expect($issues->pluck('field'))->toContain('quotations');
});

it('returns no issues for a fully ready flight', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::Confirmed]);
    Quotation::factory()->for($flightRequest)->create(['status' => QuotationStatus::Accepted]);

    $issues = app(CheckFlightReadiness::class)($flightRequest);

    expect($issues)->toBeEmpty();
});
