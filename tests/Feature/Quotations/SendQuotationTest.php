<?php

use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Customers\Models\Customer;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Actions\CreateQuotationFromServices;
use App\Domain\Quotations\Actions\SendQuotation;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Tenancy\Models\Company;
use App\Mail\QuotationMail;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;

it('emails the customer, logs it, and moves the quotation and flight forward', function () {
    Mail::fake();

    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create(['billing_email' => 'billing@customer.com']);
    $flightRequest = FlightRequest::factory()->create(['customer_id' => $customer->id]);
    Service::factory()->for($flightRequest)->create(['selling_price' => 1000]);
    $quotation = app(CreateQuotationFromServices::class)($flightRequest);

    app(SendQuotation::class)($quotation);

    Mail::assertSent(QuotationMail::class, fn ($mail) => $mail->hasTo('billing@customer.com') && $mail->quotation->is($quotation));

    $quotation->refresh();
    expect($quotation->status)->toBe(QuotationStatus::Sent);
    expect($quotation->sent_at)->not->toBeNull();
    expect($quotation->flightRequest->fresh()->status)->toBe(FlightStatus::QuotationSent);

    $entry = $quotation->communications()->first();
    expect($entry)->not->toBeNull();
    expect($entry->type)->toBe(CommunicationType::EmailOut);
    expect($entry->to_address)->toBe('billing@customer.com');
});

it('only shows the scoped leg in the emailed quotation, not every leg on the flight', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create(['billing_email' => 'billing@customer.com']);
    $flightRequest = FlightRequest::factory()->create(['customer_id' => $customer->id]);
    $legOne = $flightRequest->legs->first();
    $legTwo = $flightRequest->legs()->create([
        'sequence' => 2,
        'origin_airport_id' => $legOne->destination_airport_id,
        'destination_airport_id' => $legOne->origin_airport_id,
    ]);
    Service::factory()->for($flightRequest)->create(['flight_leg_id' => $legOne->id, 'selling_price' => 400]);
    $quotation = app(CreateQuotationFromServices::class)($flightRequest, leg: $legOne);

    $rendered = (new QuotationMail($quotation))->render();

    expect($rendered)->toContain("Leg {$legOne->sequence}:");
    expect($rendered)->not->toContain("Leg {$legTwo->sequence}:");
});

it('refuses to send when the customer has no billing email on file', function () {
    Mail::fake();

    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create(['billing_email' => null]);
    $flightRequest = FlightRequest::factory()->create(['customer_id' => $customer->id]);
    Service::factory()->for($flightRequest)->create(['selling_price' => 1000]);
    $quotation = app(CreateQuotationFromServices::class)($flightRequest);

    expect(fn () => app(SendQuotation::class)($quotation))->toThrow(RuntimeException::class);

    Mail::assertNothingSent();
    expect($quotation->fresh()->status)->toBe(QuotationStatus::Draft);
});
