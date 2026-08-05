<?php

use App\Domain\FlightRequests\Actions\CheckOperationalRisks;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;

it('flags a service marked at risk', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::AtRisk]);

    $findings = app(CheckOperationalRisks::class)($flightRequest);

    expect($findings->pluck('field'))->toContain("services.{$service->id}.status");
});

it('flags an overdue service that is still unresolved', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create([
        'deadline' => now()->subDay(),
        'status' => ServiceStatus::NotStarted,
    ]);

    $findings = app(CheckOperationalRisks::class)($flightRequest);

    expect($findings->pluck('field'))->toContain("services.{$service->id}.deadline");
});

it('flags a quote request left unanswered for more than a week', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create([
        'quote_requested_at' => now()->subDays(8),
        'quote_received_at' => null,
        'status' => ServiceStatus::SupplierRequestSent,
    ]);

    $findings = app(CheckOperationalRisks::class)($flightRequest);

    expect($findings->pluck('field'))->toContain("services.{$service->id}.quote_requested_at");
});

it('does not flag a recent quote request', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create([
        'quote_requested_at' => now()->subDays(2),
        'quote_received_at' => null,
        'status' => ServiceStatus::SupplierRequestSent,
    ]);

    $findings = app(CheckOperationalRisks::class)($flightRequest);

    expect($findings->pluck('field'))->not->toContain("services.{$service->id}.quote_requested_at");
});

it('flags a tight upcoming deadline on an unconfirmed service', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create([
        'deadline' => now()->addDay(),
        'status' => ServiceStatus::QuotationReceived,
    ]);

    $findings = app(CheckOperationalRisks::class)($flightRequest);

    expect($findings->pluck('field'))->toContain("services.{$service->id}.deadline");
});

it('does not flag a confirmed service no matter how tight the deadline or how stale the quote request', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create([
        'deadline' => now()->addHours(2),
        'quote_requested_at' => now()->subDays(30),
        'quote_received_at' => null,
        'status' => ServiceStatus::Confirmed,
    ]);

    $findings = app(CheckOperationalRisks::class)($flightRequest);

    expect($findings->where('affectedService', $service->type->label()))->toBeEmpty();
});

it('flags a sent quotation that has passed its valid-until date with no response', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $quotation = Quotation::factory()->for($flightRequest)->create([
        'status' => QuotationStatus::Sent,
        'valid_until' => now()->subDay(),
    ]);

    $findings = app(CheckOperationalRisks::class)($flightRequest);

    expect($findings->pluck('field'))->toContain("quotations.{$quotation->id}.valid_until");
});

it('does not flag a sent quotation still within its validity window', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    Quotation::factory()->for($flightRequest)->create([
        'status' => QuotationStatus::Sent,
        'valid_until' => now()->addWeek(),
    ]);

    $findings = app(CheckOperationalRisks::class)($flightRequest);

    expect($findings)->toBeEmpty();
});

it('does not flag an expired-by-date quotation that was already accepted', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    Quotation::factory()->for($flightRequest)->create([
        'status' => QuotationStatus::Accepted,
        'valid_until' => now()->subDay(),
    ]);

    $findings = app(CheckOperationalRisks::class)($flightRequest);

    expect($findings)->toBeEmpty();
});

it('returns no findings for a flight with no risky services', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    Service::factory()->for($flightRequest)->create([
        'deadline' => now()->addWeek(),
        'status' => ServiceStatus::Confirmed,
    ]);

    $findings = app(CheckOperationalRisks::class)($flightRequest);

    expect($findings)->toBeEmpty();
});
