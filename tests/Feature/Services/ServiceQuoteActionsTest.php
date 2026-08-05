<?php

use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Shared\Enums\ServiceType;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Suppliers\Models\SupplierContact;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\EditFlightRequest;
use App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers\ServicesRelationManager;
use App\Mail\SupplierQuoteRequestMail;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

it('lets Operations request a quote, but hides recording one and AI suggestions (no finance.view_costs)', function () {
    Mail::fake();

    $company = Company::factory()->create();
    $operations = userWithRoleFor($company, 'Operations');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $supplier = Supplier::factory()->for($company)->create();
    $contact = SupplierContact::factory()->for($supplier)->create();
    $service = Service::factory()->for($flightRequest)->create(['supplier_id' => $supplier->id, 'status' => ServiceStatus::NotStarted]);

    Livewire::actingAs($operations)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionVisible('requestQuote', $service)
        ->assertTableActionHidden('recordQuote', $service)
        ->assertTableActionHidden('suggestSupplier', $service)
        ->callTableAction('requestQuote', $service, data: ['supplier_contact_id' => $contact->id, 'message' => 'Please quote.'])
        ->assertHasNoTableActionErrors();

    Mail::assertSent(SupplierQuoteRequestMail::class);
    expect($service->fresh()->status)->toBe(ServiceStatus::SupplierRequestSent);
});

it('hides "request quote" until a supplier is assigned', function () {
    $company = Company::factory()->create();
    $operations = userWithRoleFor($company, 'Operations');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['supplier_id' => null]);

    Livewire::actingAs($operations)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionHidden('requestQuote', $service);
});

it('lets Procurement record a quote and see AI suggestions — the Phase 8 permission fix', function () {
    $company = Company::factory()->create();
    $procurement = userWithRoleFor($company, 'Procurement');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['cost' => null, 'status' => ServiceStatus::SupplierRequestSent]);

    Livewire::actingAs($procurement)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionVisible('recordQuote', $service)
        ->assertTableActionVisible('suggestSupplier', $service)
        ->callTableAction('recordQuote', $service, data: ['cost' => 750, 'notes' => 'Quoted by email.'])
        ->assertHasNoTableActionErrors();

    $service->refresh();
    expect((float) $service->cost)->toBe(750.0);
    expect($service->status)->toBe(ServiceStatus::QuotationReceived);
});

it('shows AI-suggested suppliers ranked with rationale in the modal', function () {
    config(['services.anthropic.key' => 'test-anthropic-key']);

    $company = Company::factory()->create();
    $procurement = userWithRoleFor($company, 'Procurement');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $supplier = Supplier::factory()->for($company)->create(['name' => 'Best Fuel Co', 'services_offered' => [ServiceType::Fuel->value]]);
    $service = Service::factory()->for($flightRequest)->create(['type' => ServiceType::Fuel, 'supplier_id' => null]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_test',
                'name' => 'recommend_suppliers',
                'input' => ['recommendations' => [['supplier_id' => $supplier->id, 'rationale' => 'Fast and reliable historically.']]],
            ]],
            'stop_reason' => 'tool_use',
        ]),
    ]);

    Livewire::actingAs($procurement)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->mountTableAction('suggestSupplier', $service)
        ->assertSee('Best Fuel Co')
        ->assertSee('Fast and reliable historically.');
});
