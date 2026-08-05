<?php

use App\Domain\FlightRequests\Actions\SendFlightRequestDigests;
use App\Domain\FlightRequests\Enums\RequestSource;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;

it('sends a database notification to a user with outstanding findings', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    FlightRequest::factory()->create(['source' => RequestSource::Email, 'reviewed_at' => null]);
    app(CurrentCompany::class)->clear();

    app(SendFlightRequestDigests::class)();

    $sales->refresh();
    expect($sales->notifications)->toHaveCount(1);
    expect($sales->notifications->first()->data['title'])->toBe('1 flight needs your attention');
});

it('does not notify a user with no outstanding findings', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);
    app(CurrentCompany::class)->clear();

    app(SendFlightRequestDigests::class)();

    $sales->refresh();
    expect($sales->notifications)->toBeEmpty();
});

it('keeps digest notifications isolated per company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CurrentCompany::class)->set($companyA->id);
    $salesA = salesUserFor($companyA);
    FlightRequest::factory()->create(['source' => RequestSource::Email, 'reviewed_at' => null]);

    app(CurrentCompany::class)->set($companyB->id);
    $salesB = salesUserFor($companyB);
    app(CurrentCompany::class)->clear();

    app(SendFlightRequestDigests::class)();

    $salesA->refresh();
    $salesB->refresh();
    expect($salesA->notifications)->toHaveCount(1);
    expect($salesB->notifications)->toBeEmpty();
});

it('runs cleanly via the scheduled console command', function () {
    $company = Company::factory()->create();
    salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);
    FlightRequest::factory()->create(['source' => RequestSource::Email, 'reviewed_at' => null]);
    app(CurrentCompany::class)->clear();

    $this->artisan('app:send-flight-request-digests')->assertExitCode(0);
});
