<?php

use App\Domain\FlightRequests\Actions\CheckFlightReadinessWarning;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;

it('warns about an unready flight departing later today', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::QuotationInProgress]);
    $flightRequest->legs->first()->update(['departure_at' => now()->addHours(3)]);
    Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::QuotationReceived]);

    expect(app(CheckFlightReadinessWarning::class)($flightRequest))->toBeTrue();
});

it('warns about an unready flight whose departure has already passed', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::QuotationInProgress]);
    $flightRequest->legs->first()->update(['departure_at' => now()->subHours(2)]);
    Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::QuotationReceived]);

    expect(app(CheckFlightReadinessWarning::class)($flightRequest))->toBeTrue();
});

it('does not warn about a flight departing well outside the window', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::QuotationInProgress]);
    $flightRequest->legs->first()->update(['departure_at' => now()->addDays(5)]);
    Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::QuotationReceived]);

    expect(app(CheckFlightReadinessWarning::class)($flightRequest))->toBeFalse();
});

it('does not warn about a flight with no departure time set yet', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::QuotationInProgress]);
    $flightRequest->legs->first()->update(['departure_at' => null]);
    Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::QuotationReceived]);

    expect(app(CheckFlightReadinessWarning::class)($flightRequest))->toBeFalse();
});

it('does not warn about a fully ready flight departing soon', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::Confirmed]);
    $flightRequest->legs->first()->update(['departure_at' => now()->addHours(3)]);
    Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::Confirmed]);
    Quotation::factory()->for($flightRequest)->create(['status' => QuotationStatus::Accepted]);

    expect(app(CheckFlightReadinessWarning::class)($flightRequest))->toBeFalse();
});

it('does not warn once a flight is already in operation, no matter how unready', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::InOperation]);
    $flightRequest->legs->first()->update(['departure_at' => now()->subHour()]);
    Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::QuotationReceived]);

    expect(app(CheckFlightReadinessWarning::class)($flightRequest))->toBeFalse();
});

it('does not warn once a flight is cancelled, no matter how unready', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::Cancelled]);
    $flightRequest->legs->first()->update(['departure_at' => now()->addHour()]);
    Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::QuotationReceived]);

    expect(app(CheckFlightReadinessWarning::class)($flightRequest))->toBeFalse();
});
