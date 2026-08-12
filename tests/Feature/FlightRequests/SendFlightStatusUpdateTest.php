<?php

use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Customers\Models\Customer;
use App\Domain\FlightRequests\Actions\SendFlightStatusUpdate;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Tenancy\Models\Company;
use App\Mail\FlightStatusUpdateMail;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;

it('emails the customer a per-service status snapshot and logs it on the flight', function () {
    Mail::fake();

    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create(['billing_email' => 'billing@customer.com']);
    $flightRequest = FlightRequest::factory()->create(['customer_id' => $customer->id, 'status' => FlightStatus::QuotationInProgress]);
    $service = Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::SupplierRequestSent]);

    app(SendFlightStatusUpdate::class)($flightRequest, 'Ground handling is in progress.');

    Mail::assertSent(FlightStatusUpdateMail::class, fn ($mail) => $mail->hasTo('billing@customer.com') && $mail->flightRequest->is($flightRequest));

    $rendered = (new FlightStatusUpdateMail($flightRequest, 'Ground handling is in progress.'))->render();
    expect($rendered)->toContain($service->type->label());
    expect($rendered)->toContain($service->status->label());
    expect($rendered)->toContain('Ground handling is in progress.');
    // A status update is operational, not financial — no price should leak in.
    expect($rendered)->not->toContain((string) $service->selling_price);

    $entry = $flightRequest->communications()->first();
    expect($entry)->not->toBeNull();
    expect($entry->type)->toBe(CommunicationType::EmailOut);
    expect($entry->to_address)->toBe('billing@customer.com');
});

it('does not change the flight status — this is not a state transition', function () {
    Mail::fake();

    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create(['billing_email' => 'billing@customer.com']);
    $flightRequest = FlightRequest::factory()->create(['customer_id' => $customer->id, 'status' => FlightStatus::NewRequest]);

    app(SendFlightStatusUpdate::class)($flightRequest);

    expect($flightRequest->fresh()->status)->toBe(FlightStatus::NewRequest);
});

it('refuses to send when the customer has no billing email on file', function () {
    Mail::fake();

    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create(['billing_email' => null]);
    $flightRequest = FlightRequest::factory()->create(['customer_id' => $customer->id]);

    expect(fn () => app(SendFlightStatusUpdate::class)($flightRequest))->toThrow(RuntimeException::class);

    Mail::assertNothingSent();
    expect($flightRequest->communications()->count())->toBe(0);
});
