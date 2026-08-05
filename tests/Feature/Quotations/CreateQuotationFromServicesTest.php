<?php

use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Actions\CreateQuotationFromServices;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Shared\Enums\ServiceType;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;

it('snapshots priced, non-cancelled services into quotation line items', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $fuel = Service::factory()->for($flightRequest)->create(['type' => ServiceType::Fuel, 'cost' => 500, 'selling_price' => 750]);
    $handling = Service::factory()->for($flightRequest)->create(['type' => ServiceType::GroundHandling, 'cost' => 200, 'selling_price' => 300]);

    $quotation = app(CreateQuotationFromServices::class)($flightRequest);

    expect($quotation->status)->toBe(QuotationStatus::Draft);
    expect($quotation->lineItems)->toHaveCount(2);
    expect($quotation->lineItems->pluck('service_id')->all())->toEqualCanonicalizing([$fuel->id, $handling->id]);
    expect($quotation->totalSellingPrice())->toBe(1050.0);
    expect($quotation->totalCost())->toBe(700.0);
    expect($quotation->profitMargin())->toBe(350.0);
});

it('excludes cancelled services', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    Service::factory()->for($flightRequest)->create(['selling_price' => 500, 'status' => ServiceStatus::Cancelled]);

    $quotation = app(CreateQuotationFromServices::class)($flightRequest);

    expect($quotation->lineItems)->toBeEmpty();
});

it('excludes services with no selling price set yet', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    Service::factory()->for($flightRequest)->create(['selling_price' => null]);

    $quotation = app(CreateQuotationFromServices::class)($flightRequest);

    expect($quotation->lineItems)->toBeEmpty();
});

it('is a frozen snapshot — changing the underlying service afterward does not change the quotation', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['selling_price' => 1000]);

    $quotation = app(CreateQuotationFromServices::class)($flightRequest);
    expect($quotation->totalSellingPrice())->toBe(1000.0);

    $service->update(['selling_price' => 5000]);

    expect($quotation->fresh('lineItems')->totalSellingPrice())->toBe(1000.0);
});

it('returns a null total cost when no line item has a cost recorded', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    Service::factory()->for($flightRequest)->create(['cost' => null, 'selling_price' => 500]);

    $quotation = app(CreateQuotationFromServices::class)($flightRequest);

    expect($quotation->totalCost())->toBeNull();
    expect($quotation->profitMargin())->toBeNull();
});

it('creates a brand new quotation each time rather than mutating an old one', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    Service::factory()->for($flightRequest)->create(['selling_price' => 500]);

    $first = app(CreateQuotationFromServices::class)($flightRequest);
    $second = app(CreateQuotationFromServices::class)($flightRequest);

    expect($first->id)->not->toBe($second->id);
    expect($flightRequest->quotations()->count())->toBe(2);
});
