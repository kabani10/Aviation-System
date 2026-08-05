<?php

use App\Domain\Customers\Models\Customer;
use App\Domain\Finance\Enums\InvoiceStatus;
use App\Domain\Finance\Models\Invoice;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Actions\CreateQuotationFromServices;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Services\Models\Service;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\EditFlightRequest;
use App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers\InvoicesRelationManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

it('walks an invoice through generate, send, and record payment for Finance', function () {
    Mail::fake();

    $company = Company::factory()->create();
    $finance = userWithRoleFor($company, 'Finance');
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create(['billing_email' => 'billing@customer.com']);
    $flightRequest = FlightRequest::factory()->create(['customer_id' => $customer->id, 'status' => FlightStatus::Completed]);
    Service::factory()->for($flightRequest)->create(['selling_price' => 750]);
    $quotation = app(CreateQuotationFromServices::class)($flightRequest);
    $quotation->update(['status' => QuotationStatus::Accepted]);

    Livewire::actingAs($finance)
        ->test(InvoicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionVisible('generate')
        ->callTableAction('generate', data: ['notes' => 'First invoice'])
        ->assertHasNoTableActionErrors();

    $invoice = $flightRequest->invoices()->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->status)->toBe(InvoiceStatus::Draft);

    Livewire::actingAs($finance)
        ->test(InvoicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionVisible('send', $invoice)
        ->callTableAction('send', $invoice)
        ->assertHasNoTableActionErrors();

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::Sent);
    expect($flightRequest->fresh()->status)->toBe(FlightStatus::Invoiced);

    Livewire::actingAs($finance)
        ->test(InvoicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionVisible('markPaid', $invoice)
        ->callTableAction('markPaid', $invoice, data: ['notes' => 'Paid by card'])
        ->assertHasNoTableActionErrors();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    expect($flightRequest->fresh()->status)->toBe(FlightStatus::Closed);
});

it('hides "generate invoice" until the flight is Completed with an accepted quotation', function () {
    $company = Company::factory()->create();
    $finance = userWithRoleFor($company, 'Finance');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::Confirmed]);

    Livewire::actingAs($finance)
        ->test(InvoicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionHidden('generate');
});

it('hides invoice workflow actions from a role without finance.manage', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create(['status' => FlightStatus::Completed]);
    Service::factory()->for($flightRequest)->create(['selling_price' => 500]);
    $quotation = app(CreateQuotationFromServices::class)($flightRequest);
    $quotation->update(['status' => QuotationStatus::Accepted]);

    Livewire::actingAs($sales)
        ->test(InvoicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionHidden('generate');
});

it('never shows one company\'s invoices to another company on the standalone resource', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CurrentCompany::class)->set($companyA->id);
    $flightA = FlightRequest::factory()->create();
    $quotationA = Quotation::factory()->for($flightA)->create(['status' => QuotationStatus::Accepted]);
    Invoice::factory()->for($flightA)->create(['quotation_id' => $quotationA->id, 'invoice_number' => 'INV-COMPANY-A']);

    $financeB = userWithRoleFor($companyB, 'Finance');

    $this->actingAs($financeB)
        ->get('/admin/invoices')
        ->assertOk()
        ->assertDontSee('INV-COMPANY-A');
});
