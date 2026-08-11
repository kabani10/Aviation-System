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
        ->assertSee('Fast and reliable historically.')
        ->assertSee('Suggested')
        ->assertTableActionDataSet(['supplier_id' => $supplier->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($service->fresh()->supplier_id)->toBe($supplier->id);
    // Regression check for the cross-request caching fix: Filament rebuilds
    // the form (and therefore re-evaluates supplierRecommendationsFor) once
    // to render the modal and again to validate on submit — without the
    // Cache::remember, that's two real Claude calls for one interaction.
    Http::assertSentCount(1);
});

it('reopens the suggest-supplier modal a second time without crashing, against a real Redis cache round-trip', function () {
    // The `array` cache driver phpunit.xml normally forces for tests never
    // actually serializes anything, so it can't catch a bug that only shows
    // up when a cached value comes back through a real serialize/unserialize
    // round-trip — exactly what happened in production: caching the
    // recommendation Collection directly (rather than a plain array) came
    // back from Redis as `__PHP_Incomplete_Class` on the second read,
    // crashing the instant anything touched it. Deliberately opts into the
    // real driver for this one test.
    config(['cache.default' => 'redis']);
    Cache::flush();

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

    // First open + save (writes the cache entry via a real Redis round-trip).
    Livewire::actingAs($procurement)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->mountTableAction('suggestSupplier', $service)
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    // Reopening it — a fresh component instance, reading the cache entry
    // Redis actually serialized rather than one still sitting in PHP memory
    // — is the exact step that crashed before this fix.
    Livewire::actingAs($procurement)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->mountTableAction('suggestSupplier', $service)
        ->assertSee('Best Fuel Co')
        ->assertSee('Fast and reliable historically.');

    Cache::flush();
});

it('lets the operator search and pick a different supplier than the AI suggested, in the same modal', function () {
    config(['services.anthropic.key' => 'test-anthropic-key']);

    $company = Company::factory()->create();
    $procurement = userWithRoleFor($company, 'Procurement');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $aiSuggested = Supplier::factory()->for($company)->create(['name' => 'AI Suggested Co', 'services_offered' => [ServiceType::Fuel->value]]);
    $chosenInstead = Supplier::factory()->for($company)->create(['name' => 'Chosen Instead Co', 'services_offered' => [ServiceType::Fuel->value]]);
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
        ->mountTableAction('suggestSupplier', $service)
        ->assertTableActionDataSet(['supplier_id' => $aiSuggested->id])
        ->setTableActionData(['supplier_id' => $chosenInstead->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($service->fresh()->supplier_id)->toBe($chosenInstead->id);
});

it('still lets the operator pick a supplier in the suggest-supplier modal when the AI call fails', function () {
    config(['services.anthropic.key' => 'test-anthropic-key']);

    $company = Company::factory()->create();
    $procurement = userWithRoleFor($company, 'Procurement');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $supplier = Supplier::factory()->for($company)->create(['name' => 'Manually Picked Co', 'services_offered' => [ServiceType::Fuel->value]]);
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
        ->mountTableAction('suggestSupplier', $service)
        ->assertSee('Could not get AI suggestions right now')
        ->setTableActionData(['supplier_id' => $supplier->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($service->fresh()->supplier_id)->toBe($supplier->id);
});

it('lets the operator override the AI-applied supplier via the normal edit form', function () {
    config(['services.anthropic.key' => 'test-anthropic-key']);

    $company = Company::factory()->create();
    $procurement = userWithRoleFor($company, 'Procurement');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $aiPicked = Supplier::factory()->for($company)->create(['name' => 'AI Picked Co', 'services_offered' => [ServiceType::Fuel->value]]);
    $preferredInstead = Supplier::factory()->for($company)->create(['name' => 'Preferred Instead Co', 'services_offered' => [ServiceType::Fuel->value]]);
    $service = Service::factory()->for($flightRequest)->create(['type' => ServiceType::Fuel, 'supplier_id' => $aiPicked->id]);

    Livewire::actingAs($procurement)
        ->test(ServicesRelationManager::class, ['ownerRecord' => $flightRequest, 'pageClass' => EditFlightRequest::class])
        ->callTableAction('edit', $service, data: [
            'flight_leg_id' => $service->flight_leg_id,
            'type' => $service->type->value,
            'status' => $service->status->value,
            'supplier_id' => $preferredInstead->id,
        ])
        ->assertHasNoTableActionErrors();

    expect($service->fresh()->supplier_id)->toBe($preferredInstead->id);
});
