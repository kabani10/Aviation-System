<?php

use App\Domain\Customers\Models\Customer;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Actions\CreateQuotationFromServices;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\EditFlightRequest;
use App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers\QuotationsRelationManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

it('walks a quotation through generate, send, and accepted for Sales', function () {
    Mail::fake();

    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create(['billing_email' => 'billing@customer.com']);
    $flightRequest = FlightRequest::factory()->create(['customer_id' => $customer->id]);
    Service::factory()->for($flightRequest)->create(['selling_price' => 500]);

    Livewire::actingAs($sales)
        ->test(QuotationsRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionVisible('generate')
        ->callTableAction('generate', data: ['notes' => 'First draft'])
        ->assertHasNoTableActionErrors();

    $quotation = $flightRequest->quotations()->first();
    expect($quotation)->not->toBeNull();
    expect($quotation->status)->toBe(QuotationStatus::Draft);

    Livewire::actingAs($sales)
        ->test(QuotationsRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionVisible('send', $quotation)
        ->callTableAction('send', $quotation)
        ->assertHasNoTableActionErrors();

    $quotation->refresh();
    expect($quotation->status)->toBe(QuotationStatus::Sent);
    expect($flightRequest->fresh()->status)->toBe(FlightStatus::QuotationSent);

    Livewire::actingAs($sales)
        ->test(QuotationsRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionVisible('markAccepted', $quotation)
        ->callTableAction('markAccepted', $quotation, data: ['notes' => 'Confirmed by phone'])
        ->assertHasNoTableActionErrors();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Accepted);
    expect($flightRequest->fresh()->status)->toBe(FlightStatus::Confirmed);
});

it('hides "generate quotation" until a service is priced', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();

    Livewire::actingAs($sales)
        ->test(QuotationsRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionHidden('generate');
});

it('hides quotation workflow actions from a view-only role', function () {
    $company = Company::factory()->create();
    $finance = userWithRoleFor($company, 'Finance');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    Service::factory()->for($flightRequest)->create(['selling_price' => 500]);
    $quotation = app(CreateQuotationFromServices::class)($flightRequest);

    Livewire::actingAs($finance)
        ->test(QuotationsRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionHidden('generate')
        ->assertTableActionHidden('send', $quotation);
});

it('never shows one company\'s quotations to another company on the standalone resource', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CurrentCompany::class)->set($companyA->id);
    $flightA = FlightRequest::factory()->create(['callsign' => 'COMPANY-A-FLIGHT']);
    Service::factory()->for($flightA)->create(['selling_price' => 500]);
    app(CreateQuotationFromServices::class)($flightA);

    $salesB = salesUserFor($companyB);

    $this->actingAs($salesB)
        ->get('/admin/quotations')
        ->assertOk()
        ->assertDontSee('COMPANY-A-FLIGHT');
});
