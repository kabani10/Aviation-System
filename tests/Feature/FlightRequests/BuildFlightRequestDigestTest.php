<?php

use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\Customers\Models\Customer;
use App\Domain\FlightRequests\Actions\BuildFlightRequestDigest;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Enums\RequestSource;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;

it('flags an unreviewed AI draft and routes it to flights.manage holders when nobody is assigned', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create([
        'source' => RequestSource::Email,
        'reviewed_at' => null,
    ]);

    $digest = app(BuildFlightRequestDigest::class)($company);

    expect($digest->has($sales->id))->toBeTrue();
    $entry = $digest->get($sales->id)->first();
    expect($entry->flightRequest->is($flightRequest))->toBeTrue();
    expect($entry->messages)->toContain('This flight was drafted by AI from an inbound email and has not been reviewed yet.');
});

it('routes findings to the flight\'s assigned users instead of flights.manage holders when someone is assigned', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    $operations = userWithRoleFor($company, 'Operations');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['passenger_count' => null]);
    $flightRequest->assignedUsers()->attach($operations->id);

    $digest = app(BuildFlightRequestDigest::class)($company);

    expect($digest->has($operations->id))->toBeTrue();
    expect($digest->has($sales->id))->toBeFalse();
});

it('excludes a flight with no outstanding findings', function () {
    $company = Company::factory()->create();
    userWithRoleFor($company, 'Operations');
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create(['billing_email' => 'ops@customer.com']);
    $aircraft = Aircraft::factory()->for($customer)->create();
    FlightRequest::factory()->create([
        'customer_id' => $customer->id,
        'aircraft_id' => $aircraft->id,
        'passenger_count' => 4,
        'crew_count' => 2,
    ]);

    $digest = app(BuildFlightRequestDigest::class)($company);

    expect($digest)->toBeEmpty();
});

it('excludes flights in a terminal status even if they would otherwise have findings', function () {
    $company = Company::factory()->create();
    userWithRoleFor($company, 'Operations');
    app(CurrentCompany::class)->set($company->id);

    FlightRequest::factory()->create([
        'passenger_count' => null,
        'status' => FlightStatus::Cancelled,
    ]);

    $digest = app(BuildFlightRequestDigest::class)($company);

    expect($digest)->toBeEmpty();
});

it('never mixes another company\'s flight requests into the digest', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CurrentCompany::class)->set($companyB->id);
    userWithRoleFor($companyB, 'Operations');
    FlightRequest::factory()->create(['source' => RequestSource::Email, 'reviewed_at' => null]);

    app(CurrentCompany::class)->set($companyA->id);
    $salesA = salesUserFor($companyA);
    FlightRequest::factory()->create(['source' => RequestSource::Email, 'reviewed_at' => null]);

    $digest = app(BuildFlightRequestDigest::class)($companyA);

    expect($digest->has($salesA->id))->toBeTrue();
    expect($digest->get($salesA->id))->toHaveCount(1);
});
