<?php

use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Enums\SupplierInquiryStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\EditFlightRequest;
use App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers\SupplierInquiriesRelationManager;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;

it('groups the inquiries table by service by default', function () {
    $company = Company::factory()->create();
    $procurement = userWithRoleFor($company, 'Procurement');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create();
    $supplier = Supplier::factory()->for($company)->create();
    $service->supplierInquiries()->create(['supplier_id' => $supplier->id, 'status' => SupplierInquiryStatus::Sent, 'requested_at' => now()]);

    $component = Livewire::actingAs($procurement)
        ->test(SupplierInquiriesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class]);

    expect($component->instance()->getTableGrouping()?->getColumn())->toBe('service_id');
    $component->assertSee($service->displayLabel());
});

it('lets Procurement record a response, setting cost and moving status to QuoteReceived', function () {
    $company = Company::factory()->create();
    $procurement = userWithRoleFor($company, 'Procurement');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create();
    $supplier = Supplier::factory()->for($company)->create();
    $inquiry = $service->supplierInquiries()->create(['supplier_id' => $supplier->id, 'status' => SupplierInquiryStatus::Sent, 'requested_at' => now()]);

    Livewire::actingAs($procurement)
        ->test(SupplierInquiriesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionVisible('recordResponse', $inquiry)
        ->callTableAction('recordResponse', $inquiry, data: ['cost' => 640, 'notes' => 'Quoted by email.'])
        ->assertHasNoTableActionErrors();

    $inquiry->refresh();
    expect((float) $inquiry->cost)->toBe(640.0);
    expect($inquiry->status)->toBe(SupplierInquiryStatus::QuoteReceived);
});

it('hides recordResponse and chooseSupplier from a role without finance.view_costs or services.manage', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create();
    $supplier = Supplier::factory()->for($company)->create();
    $sent = $service->supplierInquiries()->create(['supplier_id' => $supplier->id, 'status' => SupplierInquiryStatus::Sent, 'requested_at' => now()]);
    $quoted = $service->supplierInquiries()->create(['supplier_id' => $supplier->id, 'status' => SupplierInquiryStatus::QuoteReceived, 'cost' => 500]);

    Livewire::actingAs($sales)
        ->test(SupplierInquiriesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionHidden('recordResponse', $sent)
        ->assertTableActionHidden('chooseSupplier', $quoted);
});

it('only offers chooseSupplier once a response with a price has been recorded', function () {
    $company = Company::factory()->create();
    $procurement = userWithRoleFor($company, 'Procurement');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create();
    $supplier = Supplier::factory()->for($company)->create();
    $sent = $service->supplierInquiries()->create(['supplier_id' => $supplier->id, 'status' => SupplierInquiryStatus::Sent, 'requested_at' => now()]);
    $quoted = $service->supplierInquiries()->create(['supplier_id' => $supplier->id, 'status' => SupplierInquiryStatus::QuoteReceived, 'cost' => 500]);

    Livewire::actingAs($procurement)
        ->test(SupplierInquiriesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionHidden('chooseSupplier', $sent)
        ->assertTableActionVisible('chooseSupplier', $quoted);
});

it('choosing a supplier from the table updates the Service and marks the inquiry Chosen', function () {
    $company = Company::factory()->create();
    $procurement = userWithRoleFor($company, 'Procurement');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::SupplierRequestSent, 'supplier_id' => null, 'cost' => null]);
    $supplier = Supplier::factory()->for($company)->create();
    $inquiry = $service->supplierInquiries()->create(['supplier_id' => $supplier->id, 'status' => SupplierInquiryStatus::QuoteReceived, 'cost' => 820]);

    Livewire::actingAs($procurement)
        ->test(SupplierInquiriesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->callTableAction('chooseSupplier', $inquiry)
        ->assertHasNoTableActionErrors();

    $service->refresh();
    expect($service->supplier_id)->toBe($supplier->id);
    expect((float) $service->cost)->toBe(820.0);
    expect($service->status)->toBe(ServiceStatus::QuotationReceived);
    expect($inquiry->fresh()->status)->toBe(SupplierInquiryStatus::Chosen);
});
