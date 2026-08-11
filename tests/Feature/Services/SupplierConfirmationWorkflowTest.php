<?php

use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Actions\ApplySupplierConfirmation;
use App\Domain\Services\Actions\SendSupplierConfirmation;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Enums\SupplierInquiryStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Services\Models\SupplierInquiry;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Suppliers\Models\SupplierContact;
use App\Domain\Tenancy\Models\Company;
use App\Mail\SupplierBookingConfirmationMail;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;

function chosenInquiryFor(Company $company, string $contactEmail = 'quotes@fuelco.com'): SupplierInquiry
{
    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::QuotationReceived]);
    $supplier = Supplier::factory()->for($company)->create();
    $contact = SupplierContact::factory()->for($supplier)->create(['email' => $contactEmail]);

    $inquiry = $service->supplierInquiries()->create([
        'supplier_id' => $supplier->id,
        'supplier_contact_id' => $contact->id,
        'status' => SupplierInquiryStatus::Chosen,
        'cost' => 900,
        'requested_at' => now()->subHour(),
        'responded_at' => now(),
    ]);

    $service->update(['supplier_id' => $supplier->id, 'cost' => 900]);

    return $inquiry;
}

it('emails the chosen supplier and sets confirmation_requested_at', function () {
    Mail::fake();

    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $inquiry = chosenInquiryFor($company, 'quotes@fuelco.com');

    app(SendSupplierConfirmation::class)($inquiry, 'Please confirm.');

    Mail::assertSent(SupplierBookingConfirmationMail::class, fn ($mail) => $mail->hasTo('quotes@fuelco.com') && $mail->inquiry->is($inquiry));

    $inquiry->refresh();
    expect($inquiry->confirmation_requested_at)->not->toBeNull();
    expect($inquiry->status)->toBe(SupplierInquiryStatus::Chosen);

    $entry = $inquiry->communications()->latest('occurred_at')->first();
    expect($entry->type)->toBe(CommunicationType::EmailOut);
    expect($entry->to_address)->toBe('quotes@fuelco.com');
});

it('applying a confirmation sets the inquiry and Service confirmed, advancing status', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $inquiry = chosenInquiryFor($company);
    $inquiry->update(['confirmation_requested_at' => now()->subHour()]);

    app(ApplySupplierConfirmation::class)($inquiry, 'Confirmed by phone.');

    $inquiry->refresh();
    expect($inquiry->status)->toBe(SupplierInquiryStatus::Confirmed);
    expect($inquiry->confirmed_at)->not->toBeNull();

    $service = $inquiry->service->fresh();
    expect($service->status)->toBe(ServiceStatus::Confirmed);
    expect($service->supplier_confirmed_at)->not->toBeNull();

    $entry = $inquiry->communications()->latest('occurred_at')->first();
    expect($entry->type)->toBe(CommunicationType::EmailIn);
    expect($entry->body)->toBe('Confirmed by phone.');
});

it('does not regress a service already completed when a confirmation is applied late', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $inquiry = chosenInquiryFor($company);
    $inquiry->service->update(['status' => ServiceStatus::Completed]);

    app(ApplySupplierConfirmation::class)($inquiry);

    expect($inquiry->service->fresh()->status)->toBe(ServiceStatus::Completed);
    // The fact itself still gets recorded even though status doesn't move.
    expect($inquiry->service->fresh()->supplier_confirmed_at)->not->toBeNull();
});
