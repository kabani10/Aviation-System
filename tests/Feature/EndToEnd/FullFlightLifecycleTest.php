<?php

use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\Customers\Models\Customer;
use App\Domain\Finance\Actions\ComputeFinancialSummary;
use App\Domain\Finance\Enums\InvoiceStatus;
use App\Domain\Finance\Models\Invoice;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\ReferenceData\Models\Airport;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Enums\SupplierInquiryStatus;
use App\Domain\Shared\Enums\ServiceType;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Suppliers\Models\SupplierContact;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\Aircraft\AircraftResource\Pages\CreateAircraft;
use App\Filament\Resources\Customers\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\CreateFlightRequest;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\EditFlightRequest;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\ViewFlightRequest;
use App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers\QuotationsRelationManager;
use App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers\ServicesRelationManager;
use App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers\SupplierInquiriesRelationManager;
use App\Mail\InvoiceMail;
use App\Mail\QuotationMail;
use App\Mail\SupplierQuoteRequestMail;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * Registers a company through the real /register endpoint (same as an
 * actual signup) and returns its Admin. Every downstream step in this file
 * acts through this user, exactly like the one real operator this app has
 * had so far.
 */
function registerCompanyForE2E(string $companyName, string $adminName, string $email): array
{
    $response = test()->post('/register', [
        'company_name' => $companyName,
        'admin_name' => $adminName,
        'admin_email' => $email,
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertRedirect('/admin');

    // Company has no tenant scope of its own, but User does — and within a
    // single test process (unlike a real request, which gets a fresh
    // container) CurrentCompany can still be holding whichever company was
    // active before this call. Set it from the company we just found, then
    // look the admin up through the relation rather than a bare scoped
    // query, so this is correct regardless of ambient state.
    $company = Company::where('name', $companyName)->firstOrFail();
    app(CurrentCompany::class)->set($company->id);
    $admin = $company->users()->where('email', $email)->firstOrFail();

    return [$company, $admin];
}

/**
 * Drives one company's admin through the entire lifecycle the same way an
 * operator would click through it: customer -> aircraft -> flight request
 * -> priced + supplier-confirmed service -> quotation (draft, sent,
 * accepted) -> flight in operation -> completed -> invoice (draft, sent,
 * paid) -> flight closed. Returns every record created along the way so
 * callers can assert against them.
 */
function runFullLifecycleForE2E(Company $company, User $admin, string $callsign, string $customerName): array
{
    app(CurrentCompany::class)->set($company->id);

    Livewire::actingAs($admin)
        ->test(CreateCustomer::class)
        ->fillForm([
            'name' => $customerName,
            'billing_email' => strtolower(str_replace(' ', '.', $customerName)).'@customer.example',
            'payment_terms' => 'Net 30',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $customer = $company->fresh()->customers()->where('name', $customerName)->firstOrFail();

    Livewire::actingAs($admin)
        ->test(CreateAircraft::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'registration' => $callsign,
            'aircraft_type' => 'Gulfstream G650',
            'mtow_kg' => 41277,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $aircraft = $customer->aircraft()->firstOrFail();

    [$origin, $destination] = Airport::query()->inRandomOrder()->take(2)->get();

    Livewire::actingAs($admin)
        ->test(CreateFlightRequest::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'aircraft_id' => $aircraft->id,
            'callsign' => $callsign,
            'origin_airport_id' => $origin->id,
            'destination_airport_id' => $destination->id,
            'departure_at' => now()->addDay(),
            'arrival_at' => now()->addDay()->addHours(3),
            'passenger_count' => 6,
            'crew_count' => 2,
            'status' => FlightStatus::NewRequest->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $flightRequest = $company->fresh()->flightRequests()->where('callsign', $callsign)->firstOrFail();
    expect($flightRequest->status)->toBe(FlightStatus::NewRequest);

    $supplier = Supplier::factory()->for($company)->create(['services_offered' => [ServiceType::GroundHandling->value]]);
    $contact = SupplierContact::factory()->for($supplier)->create();

    Livewire::actingAs($admin)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->callTableAction('create', data: [
            'type' => ServiceType::GroundHandling->value,
            'status' => ServiceStatus::NotStarted->value,
            'selling_price' => 1500,
        ])
        ->assertHasNoTableActionErrors();

    $service = $flightRequest->services()->firstOrFail();

    // Multi-supplier RFQ flow (Phase 15): send an inquiry, record what came
    // back on that inquiry, then choose it — supplier_id/cost land on the
    // Service only once chosen, not the moment a supplier is picked to ask.
    Livewire::actingAs($admin)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->callTableAction('sendInquiry', $service, data: [
            'supplier_id' => $supplier->id,
            'supplier_contact_id' => $contact->id,
            'message' => 'Please quote ground handling.',
        ])
        ->assertHasNoTableActionErrors();

    expect($service->fresh()->status)->toBe(ServiceStatus::SupplierRequestSent);

    $inquiry = $service->supplierInquiries()->firstOrFail();

    Livewire::actingAs($admin)
        ->test(SupplierInquiriesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->callTableAction('recordResponse', $inquiry, data: ['cost' => 750, 'notes' => 'Quoted by email.'])
        ->assertHasNoTableActionErrors();

    $inquiry = $inquiry->fresh();
    expect($inquiry->status)->toBe(SupplierInquiryStatus::QuoteReceived);
    expect((float) $inquiry->cost)->toBe(750.0);

    Livewire::actingAs($admin)
        ->test(SupplierInquiriesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->callTableAction('chooseSupplier', $inquiry)
        ->assertHasNoTableActionErrors();

    $service->refresh();
    expect($service->status)->toBe(ServiceStatus::QuotationReceived);
    expect($service->supplier_id)->toBe($supplier->id);
    expect((float) $service->cost)->toBe(750.0);

    // Supplier confirmed by phone — the operator's own manual step before a
    // flight is considered ready, distinct from just having a priced quote.
    $service->update(['status' => ServiceStatus::Confirmed, 'supplier_confirmed_at' => now()]);

    Livewire::actingAs($admin)
        ->test(QuotationsRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionVisible('generate')
        ->callTableAction('generate', data: ['notes' => 'Initial quotation'])
        ->assertHasNoTableActionErrors();

    $quotation = $flightRequest->quotations()->firstOrFail();
    expect($quotation->status)->toBe(QuotationStatus::Draft);

    Livewire::actingAs($admin)
        ->test(QuotationsRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->callTableAction('send', $quotation)
        ->assertHasNoTableActionErrors();

    $quotation->refresh();
    $flightRequest->refresh();
    expect($quotation->status)->toBe(QuotationStatus::Sent);
    expect($flightRequest->status)->toBe(FlightStatus::QuotationSent);

    Livewire::actingAs($admin)
        ->test(QuotationsRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->callTableAction('markAccepted', $quotation, data: ['notes' => 'Customer confirmed by phone'])
        ->assertHasNoTableActionErrors();

    $quotation->refresh();
    $flightRequest->refresh();
    expect($quotation->status)->toBe(QuotationStatus::Accepted);
    expect($flightRequest->status)->toBe(FlightStatus::Confirmed);

    Livewire::actingAs($admin)
        ->test(ViewFlightRequest::class, ['record' => $flightRequest->getRouteKey()])
        ->mountAction('markInOperation')
        ->assertHasNoActionErrors() // modal rendered with no readiness issues surfaced as errors
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $flightRequest->refresh();
    expect($flightRequest->status)->toBe(FlightStatus::InOperation);
    expect($flightRequest->operation_started_at)->not->toBeNull();

    Livewire::actingAs($admin)
        ->test(ViewFlightRequest::class, ['record' => $flightRequest->getRouteKey()])
        ->mountAction('markCompleted')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $flightRequest->refresh();
    expect($flightRequest->status)->toBe(FlightStatus::Completed);
    expect($flightRequest->completed_at)->not->toBeNull();

    Livewire::actingAs($admin)
        ->test(InvoicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionVisible('generate')
        ->callTableAction('generate', data: ['notes' => 'Final invoice'])
        ->assertHasNoTableActionErrors();

    $invoice = $flightRequest->invoices()->firstOrFail();
    expect($invoice->status)->toBe(InvoiceStatus::Draft);

    Livewire::actingAs($admin)
        ->test(InvoicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->callTableAction('send', $invoice)
        ->assertHasNoTableActionErrors();

    $invoice->refresh();
    $flightRequest->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::Sent);
    expect($flightRequest->status)->toBe(FlightStatus::Invoiced);

    Livewire::actingAs($admin)
        ->test(InvoicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->callTableAction('markPaid', $invoice, data: ['notes' => 'Paid by wire transfer'])
        ->assertHasNoTableActionErrors();

    $invoice->refresh();
    $flightRequest->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::Paid);
    expect($flightRequest->status)->toBe(FlightStatus::Closed);

    return compact('customer', 'aircraft', 'flightRequest', 'service', 'quotation', 'invoice');
}

it('walks a self-registered company through the entire flight lifecycle without error, end to end', function () {
    Mail::fake();

    [$company, $admin] = registerCompanyForE2E('Alpha Aviation Services', 'Alice Admin', 'alice@alpha-aviation.example');

    $records = runFullLifecycleForE2E($company, $admin, 'N650AA', 'Alpha Test Customer');

    Mail::assertSent(SupplierQuoteRequestMail::class);
    Mail::assertSent(QuotationMail::class);
    Mail::assertSent(InvoiceMail::class);

    // The whole point of the invoice/quotation "never store a second copy"
    // design (see ARCHITECTURE.md) — the final invoice total must trace
    // straight back to the one Quotation, itself traced to the one Service.
    expect($records['invoice']->totalAmount())->toBe(1500.0);
    expect($records['invoice']->profitMargin())->toBe(750.0);

    app(CurrentCompany::class)->set($company->id);
    $summary = app(ComputeFinancialSummary::class)();

    expect($summary->totalInvoiced)->toBe(1500.0);
    expect($summary->totalCollected)->toBe(1500.0);
    expect($summary->totalOutstanding)->toBe(0.0);
    expect($summary->overdueCount)->toBe(0);
    expect($summary->totalProfitMargin)->toBe(750.0);
});

it('keeps two independently self-registered companies fully isolated across the entire lifecycle', function () {
    Mail::fake();

    [$companyA, $adminA] = registerCompanyForE2E('Alpha Aviation Services', 'Alice Admin', 'alice@alpha-aviation.example');
    [$companyB, $adminB] = registerCompanyForE2E('Bravo Trip Support', 'Bob Admin', 'bob@bravo-trip.example');

    $a = runFullLifecycleForE2E($companyA, $adminA, 'N650AA', 'Alpha Test Customer');
    $b = runFullLifecycleForE2E($companyB, $adminB, 'N800BB', 'Bravo Test Customer');

    // Both companies' own chains completed independently and correctly —
    // proves the isolation below isn't hiding a chain that half-failed.
    expect($a['flightRequest']->fresh()->status)->toBe(FlightStatus::Closed);
    expect($b['flightRequest']->fresh()->status)->toBe(FlightStatus::Closed);

    // Database-level scoping (CompanyScope + CurrentCompany), one resource at a time.
    app(CurrentCompany::class)->set($companyB->id);
    expect(Customer::query()->pluck('id'))->not->toContain($a['customer']->id);
    expect(Aircraft::query()->pluck('id'))->not->toContain($a['aircraft']->id);
    expect(FlightRequest::query()->pluck('id'))->not->toContain($a['flightRequest']->id);
    expect(Quotation::query()->pluck('id'))->not->toContain($a['quotation']->id);
    expect(Invoice::query()->pluck('id'))->not->toContain($a['invoice']->id);

    app(CurrentCompany::class)->set($companyA->id);
    expect(Customer::query()->pluck('id'))->not->toContain($b['customer']->id);
    expect(FlightRequest::query()->pluck('id'))->not->toContain($b['flightRequest']->id);
    expect(Quotation::query()->pluck('id'))->not->toContain($b['quotation']->id);
    expect(Invoice::query()->pluck('id'))->not->toContain($b['invoice']->id);

    // Full HTTP round trip through the real admin panel, as the registered
    // Admin — proves the panel's own queries (not just the model layer)
    // never leak across tenants. Admin accounts aren't required to have 2FA
    // enabled, so a plain actingAs() reaches the panel with no extra setup.
    // Only one identity's panel session is exercised per test process —
    // Laravel's test client shares app/container state across simulated
    // requests within a single test, and switching the authenticated user
    // mid-test on the *same* panel guard is unreliable there; the
    // bidirectional check above (querying the model layer directly under
    // each company's tenant context) already proves isolation both ways.
    $this->actingAs($adminB)
        ->get('/admin/customers')->assertOk()->assertDontSee('Alpha Test Customer');
    $this->actingAs($adminB)
        ->get('/admin/flight-requests')->assertOk()->assertDontSee('N650AA');
    $this->actingAs($adminB)
        ->get('/admin/quotations')->assertOk()->assertDontSee('N650AA');
    $this->actingAs($adminB)
        ->get('/admin/invoices')->assertOk();

    // Financial summaries never blend — each company's Admin sees only its
    // own invoice.
    app(CurrentCompany::class)->set($companyA->id);
    expect(app(ComputeFinancialSummary::class)()->totalCollected)->toBe(1500.0);

    app(CurrentCompany::class)->set($companyB->id);
    expect(app(ComputeFinancialSummary::class)()->totalCollected)->toBe(1500.0);
});
