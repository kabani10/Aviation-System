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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

it('lets Operations send an RFQ with a plain supplier picker (no finance.view_costs, no AI suggestions)', function () {
    Mail::fake();

    $company = Company::factory()->create();
    $operations = userWithRoleFor($company, 'Operations');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $supplier = Supplier::factory()->for($company)->create();
    $contact = SupplierContact::factory()->for($supplier)->create();
    $service = Service::factory()->for($flightRequest)->create(['status' => ServiceStatus::NotStarted]);

    Livewire::actingAs($operations)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionVisible('sendInquiry', $service)
        ->mountTableAction('sendInquiry', $service)
        ->assertDontSee('Suggested suppliers')
        ->setTableActionData(['supplier_id' => $supplier->id, 'supplier_contact_id' => $contact->id, 'message' => 'Please quote.'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    Mail::assertSent(SupplierQuoteRequestMail::class);
    expect($service->fresh()->status)->toBe(ServiceStatus::SupplierRequestSent);
    expect($service->supplierInquiries()->count())->toBe(1);
    expect($service->supplierInquiries()->first()->supplier_id)->toBe($supplier->id);
});

it('does not require a supplier already assigned on the service before sending an RFQ', function () {
    $company = Company::factory()->create();
    $operations = userWithRoleFor($company, 'Operations');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['supplier_id' => null]);

    Livewire::actingAs($operations)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->assertTableActionVisible('sendInquiry', $service);
});

it('lets Procurement see AI suggestions in the Send RFQ modal — the Phase 8 permission fix', function () {
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
        ->mountTableAction('sendInquiry', $service)
        ->assertSee('Best Fuel Co')
        ->assertSee('Fast and reliable historically.')
        ->assertSee('Suggested')
        ->assertTableActionDataSet(['supplier_id' => $supplier->id]);

    Http::assertSentCount(1);
});

it('reopens the Send RFQ modal a second time without crashing, against a real Redis cache round-trip', function () {
    // Same regression this guarded against in Phase 8 — a cached Collection
    // of readonly DTOs coming back __PHP_Incomplete_Class through a real
    // Redis round-trip, not something the array-driver test default can
    // catch. Deliberately opts into the real driver for this one test.
    config(['cache.default' => 'redis']);
    Cache::flush();

    config(['services.anthropic.key' => 'test-anthropic-key']);

    $company = Company::factory()->create();
    $procurement = userWithRoleFor($company, 'Procurement');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $supplier = Supplier::factory()->for($company)->create(['name' => 'Best Fuel Co', 'services_offered' => [ServiceType::Fuel->value]]);
    $contact = SupplierContact::factory()->for($supplier)->create();
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
        ->mountTableAction('sendInquiry', $service)
        ->setTableActionData(['supplier_id' => $supplier->id, 'supplier_contact_id' => $contact->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    Livewire::actingAs($procurement)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->mountTableAction('sendInquiry', $service)
        ->assertSee('Best Fuel Co')
        ->assertSee('Fast and reliable historically.');

    Cache::flush();
});

it('lets the operator search and pick a different supplier than the AI suggested, in the Send RFQ modal', function () {
    config(['services.anthropic.key' => 'test-anthropic-key']);

    $company = Company::factory()->create();
    $procurement = userWithRoleFor($company, 'Procurement');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $aiSuggested = Supplier::factory()->for($company)->create(['name' => 'AI Suggested Co', 'services_offered' => [ServiceType::Fuel->value]]);
    $chosenInstead = Supplier::factory()->for($company)->create(['name' => 'Chosen Instead Co', 'services_offered' => [ServiceType::Fuel->value]]);
    $contact = SupplierContact::factory()->for($chosenInstead)->create();
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
                'input' => ['recommendations' => [['supplier_id' => $aiSuggested->id, 'rationale' => 'Top pick.']]],
            ]],
            'stop_reason' => 'tool_use',
        ]),
    ]);

    Livewire::actingAs($procurement)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->mountTableAction('sendInquiry', $service)
        ->assertTableActionDataSet(['supplier_id' => $aiSuggested->id])
        ->setTableActionData(['supplier_id' => $chosenInstead->id, 'supplier_contact_id' => $contact->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $inquiry = $service->supplierInquiries()->latest()->first();
    expect($inquiry->supplier_id)->toBe($chosenInstead->id);
});

it('still lets the operator pick a supplier in the Send RFQ modal when the AI call fails', function () {
    config(['services.anthropic.key' => 'test-anthropic-key']);

    $company = Company::factory()->create();
    $procurement = userWithRoleFor($company, 'Procurement');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $supplier = Supplier::factory()->for($company)->create(['name' => 'Manually Picked Co', 'services_offered' => [ServiceType::Fuel->value]]);
    $contact = SupplierContact::factory()->for($supplier)->create();
    $service = Service::factory()->for($flightRequest)->create(['type' => ServiceType::Fuel, 'supplier_id' => null]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'I need more information.']],
            'stop_reason' => 'end_turn',
        ]),
    ]);

    Livewire::actingAs($procurement)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->mountTableAction('sendInquiry', $service)
        ->assertSee('Could not get AI suggestions right now')
        ->setTableActionData(['supplier_id' => $supplier->id, 'supplier_contact_id' => $contact->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $inquiry = $service->supplierInquiries()->latest()->first();
    expect($inquiry->supplier_id)->toBe($supplier->id);
});

it('lets the operator manually override supplier_id/cost via the normal edit form, bypassing the RFQ flow entirely', function () {
    $company = Company::factory()->create();
    $procurement = userWithRoleFor($company, 'Procurement');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $preferred = Supplier::factory()->for($company)->create(['name' => 'Preferred Co', 'services_offered' => [ServiceType::Fuel->value]]);
    $service = Service::factory()->for($flightRequest)->create(['type' => ServiceType::Fuel, 'supplier_id' => null]);

    Livewire::actingAs($procurement)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->callTableAction('edit', $service, data: [
            'flight_leg_id' => $service->flight_leg_id,
            'type' => $service->type->value,
            'status' => $service->status->value,
            'supplier_id' => $preferred->id,
        ])
        ->assertHasNoTableActionErrors();

    expect($service->fresh()->supplier_id)->toBe($preferred->id);
    expect($service->supplierInquiries()->count())->toBe(0);
});
