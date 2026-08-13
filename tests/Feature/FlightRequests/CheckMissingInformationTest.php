<?php

use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\Customers\Models\Customer;
use App\Domain\Documents\Actions\UploadDocument;
use App\Domain\FlightRequests\Actions\CheckMissingInformation;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Shared\Enums\ServiceType;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('documents'));

it('flags missing passenger count, crew count, and customer billing email', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create(['billing_email' => null]);
    $aircraft = Aircraft::factory()->for($customer)->create();
    $flightRequest = FlightRequest::factory()->create([
        'customer_id' => $customer->id,
        'aircraft_id' => $aircraft->id,
        'passenger_count' => null,
        'crew_count' => null,
    ]);

    $findings = app(CheckMissingInformation::class)($flightRequest);

    expect($findings->pluck('field'))->toContain('passenger_count', 'crew_count', 'customer.billing_email');
});

it('flags a missing customer without also flagging a missing billing email for it', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['customer_id' => null, 'aircraft_id' => null]);

    $findings = app(CheckMissingInformation::class)($flightRequest);

    expect($findings->pluck('field'))->toContain('customer_id', 'aircraft_id');
    expect($findings->pluck('field'))->not->toContain('customer.billing_email');
});

it('flags a leg with a missing departure or arrival time', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $leg = $flightRequest->legs()->first();
    $leg->update(['departure_at' => null, 'arrival_at' => null]);

    $findings = app(CheckMissingInformation::class)($flightRequest);

    expect($findings->pluck('field'))->toContain("legs.{$leg->id}.departure_at", "legs.{$leg->id}.arrival_at");
});

it('does not flag a leg that has both departure and arrival times', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $leg = $flightRequest->legs()->first();

    $findings = app(CheckMissingInformation::class)($flightRequest);

    expect($findings->pluck('field'))->not->toContain("legs.{$leg->id}.departure_at", "legs.{$leg->id}.arrival_at");
});

it('flags an expired document on the aircraft', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();

    app(UploadDocument::class)(
        $flightRequest->aircraft,
        UploadedFile::fake()->create('insurance.pdf', 10),
        'insurance_certificate',
        expiresAt: now()->subDay()->toDateTimeString(),
    );

    $findings = app(CheckMissingInformation::class)($flightRequest);

    expect($findings->pluck('field'))->toContain('aircraft.documents');
});

it('does not flag a still-valid aircraft document', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();

    app(UploadDocument::class)(
        $flightRequest->aircraft,
        UploadedFile::fake()->create('insurance.pdf', 10),
        'insurance_certificate',
        expiresAt: now()->addYear()->toDateTimeString(),
    );

    $findings = app(CheckMissingInformation::class)($flightRequest);

    expect($findings->pluck('field'))->not->toContain('aircraft.documents');
});

it('flags a permit service with no supporting documents attached', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['type' => ServiceType::LandingPermit]);

    $findings = app(CheckMissingInformation::class)($flightRequest);

    expect($findings->pluck('field'))->toContain("services.{$service->id}.documents");
});

it('does not flag a non-permit service for missing documents', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['type' => ServiceType::Fuel]);

    $findings = app(CheckMissingInformation::class)($flightRequest);

    expect($findings->pluck('field'))->not->toContain("services.{$service->id}.documents");
});

it('flags a service with no supplier assigned, unless it is cancelled', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $active = Service::factory()->for($flightRequest)->create(['type' => ServiceType::Fuel, 'supplier_id' => null, 'status' => ServiceStatus::NotStarted]);
    $cancelled = Service::factory()->for($flightRequest)->create(['type' => ServiceType::Catering, 'supplier_id' => null, 'status' => ServiceStatus::Cancelled]);

    $findings = app(CheckMissingInformation::class)($flightRequest);

    expect($findings->pluck('field'))->toContain("services.{$active->id}.supplier_id");
    expect($findings->pluck('field'))->not->toContain("services.{$cancelled->id}.supplier_id");
});

it('does not flag a service that already has a supplier', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $supplier = Supplier::factory()->for($company)->create();
    $service = Service::factory()->for($flightRequest)->create(['type' => ServiceType::Fuel, 'supplier_id' => $supplier->id]);

    $findings = app(CheckMissingInformation::class)($flightRequest);

    expect($findings->pluck('field'))->not->toContain("services.{$service->id}.supplier_id");
});

it('returns no findings for a fully detailed flight request with no services', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create(['billing_email' => 'ops@customer.com']);
    $aircraft = Aircraft::factory()->for($customer)->create();
    $flightRequest = FlightRequest::factory()->create([
        'customer_id' => $customer->id,
        'aircraft_id' => $aircraft->id,
        'passenger_count' => 4,
        'crew_count' => 2,
    ]);

    $findings = app(CheckMissingInformation::class)($flightRequest);

    expect($findings)->toBeEmpty();
});
