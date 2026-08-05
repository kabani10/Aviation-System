<?php

use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Actions\CreateQuotationFromServices;
use App\Domain\Quotations\Actions\RecordQuotationResponse;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;

it('confirms the flight when the quotation is accepted', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::QuotationSent]);
    Service::factory()->for($flightRequest)->create(['selling_price' => 500]);
    $quotation = app(CreateQuotationFromServices::class)($flightRequest);

    app(RecordQuotationResponse::class)($quotation, QuotationStatus::Accepted, 'Customer called to confirm.');

    $quotation->refresh();
    expect($quotation->status)->toBe(QuotationStatus::Accepted);
    expect($quotation->responded_at)->not->toBeNull();
    expect($quotation->notes)->toBe('Customer called to confirm.');
    expect($quotation->flightRequest->fresh()->status)->toBe(FlightStatus::Confirmed);
});

it('does not confirm the flight when the quotation is rejected', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::QuotationSent]);
    Service::factory()->for($flightRequest)->create(['selling_price' => 500]);
    $quotation = app(CreateQuotationFromServices::class)($flightRequest);

    app(RecordQuotationResponse::class)($quotation, QuotationStatus::Rejected);

    $quotation->refresh();
    expect($quotation->status)->toBe(QuotationStatus::Rejected);
    expect($quotation->flightRequest->fresh()->status)->toBe(FlightStatus::QuotationSent);
});

it('rejects a response that is not Accepted or Rejected', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    Service::factory()->for($flightRequest)->create(['selling_price' => 500]);
    $quotation = app(CreateQuotationFromServices::class)($flightRequest);

    expect(fn () => app(RecordQuotationResponse::class)($quotation, QuotationStatus::Draft))
        ->toThrow(InvalidArgumentException::class);
});
