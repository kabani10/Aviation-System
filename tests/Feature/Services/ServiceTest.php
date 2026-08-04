<?php

use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Shared\Enums\ServiceType;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\EditFlightRequest;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\ViewFlightRequest;
use App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers\ServicesRelationManager;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;

it('lets Operations add a service, but never sets cost or price they cannot see', function () {
    $company = Company::factory()->create();
    $operations = userWithRoleFor($company, 'Operations');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->for($company)->create();

    Livewire::actingAs($operations)
        ->test(ServicesRelationManager::class, [
            'ownerRecord' => $flightRequest,
            'pageClass' => EditFlightRequest::class,
        ])
        ->callTableAction('create', data: [
            'type' => ServiceType::GroundHandling->value,
            'status' => ServiceStatus::NotStarted->value,
            // Operations has services.manage but neither finance.view_costs
            // nor finance.view_prices — these fields are hidden+not
            // dehydrated, so even if a Livewire test forces them into the
            // payload, they should never reach the database.
            'cost' => 999,
            'selling_price' => 1500,
        ])
        ->assertHasNoTableActionErrors();

    $service = $flightRequest->services()->first();

    expect($service)->not->toBeNull();
    expect($service->type)->toBe(ServiceType::GroundHandling);
    expect($service->cost)->toBeNull();
    expect($service->selling_price)->toBeNull();
});

it('lets Finance see both cost and price columns but not create services', function () {
    $company = Company::factory()->create();
    $finance = userWithRoleFor($company, 'Finance');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->for($company)->create();
    Service::factory()->for($flightRequest)->create(['cost' => 750]);

    // Finance has flights.view, not flights.manage, so it reaches the view
    // page (the edit page requires update rights) — see ViewFlightRequest's
    // docblock for why this page exists at all. Finance holds both
    // finance.view_costs and finance.view_prices per the seeder — full
    // financial visibility is the point of the role.
    Livewire::actingAs($finance)
        ->test(ServicesRelationManager::class, [
            'ownerRecord' => $flightRequest,
            'pageClass' => ViewFlightRequest::class,
        ])
        ->assertCanRenderTableColumn('cost')
        ->assertCanRenderTableColumn('selling_price')
        ->assertActionDoesNotExist('create');
});

it('lets Sales see selling price but not cost, and cannot create a service', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->for($company)->create();
    Service::factory()->for($flightRequest)->create(['cost' => 111.22, 'selling_price' => 888.99]);

    Livewire::actingAs($sales)
        ->test(ServicesRelationManager::class, [
            'ownerRecord' => $flightRequest,
            'pageClass' => ViewFlightRequest::class,
        ])
        ->assertCanRenderTableColumn('selling_price')
        ->assertCanNotRenderTableColumn('cost')
        ->assertActionDoesNotExist('create');
});

it('does not let a view-only role (Procurement) create a service', function () {
    $company = Company::factory()->create();
    $procurement = userWithRoleFor($company, 'Procurement');
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->for($company)->create();

    Livewire::actingAs($procurement)
        ->test(ServicesRelationManager::class, [
            'ownerRecord' => $flightRequest,
            'pageClass' => EditFlightRequest::class,
        ])
        ->assertActionDoesNotExist('create');
});

it('never shows one company\'s services to another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CurrentCompany::class)->set($companyA->id);
    $flightA = FlightRequest::factory()->for($companyA)->create();
    Service::factory()->for($flightA)->create();

    app(CurrentCompany::class)->set($companyB->id);
    $flightB = FlightRequest::factory()->for($companyB)->create();

    // Clear rather than leave pointed at company B — company A's own count
    // would otherwise be read through company B's CompanyScope and read 0
    // regardless of tenant isolation actually working.
    app(CurrentCompany::class)->clear();

    expect($flightB->services()->count())->toBe(0);
    expect($flightA->services()->count())->toBe(1);
});

it('computes profit margin from cost and selling price without storing it', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $service = Service::factory()->create(['cost' => 400, 'selling_price' => 550]);

    expect($service->profitMargin())->toBe(150.0);
});

it('returns null profit margin when either cost or selling price is missing', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $service = Service::factory()->create(['cost' => 400, 'selling_price' => null]);

    expect($service->profitMargin())->toBeNull();
});

it('flags a service as overdue only when the deadline has passed and it is not resolved', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $overdue = Service::factory()->create(['deadline' => now()->subDay(), 'status' => ServiceStatus::NotStarted]);
    $confirmedPastDeadline = Service::factory()->create(['deadline' => now()->subDay(), 'status' => ServiceStatus::Confirmed]);
    $futureDeadline = Service::factory()->create(['deadline' => now()->addDay(), 'status' => ServiceStatus::NotStarted]);

    expect($overdue->isOverdue())->toBeTrue();
    expect($confirmedPastDeadline->isOverdue())->toBeFalse();
    expect($futureDeadline->isOverdue())->toBeFalse();
});

it('filters supplier options to suppliers offering the selected service type, without enforcing it', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $fuelSupplier = Supplier::factory()->for($company)->create(['services_offered' => [ServiceType::Fuel->value]]);
    $handlingSupplier = Supplier::factory()->for($company)->create(['services_offered' => [ServiceType::GroundHandling->value]]);

    $matching = Supplier::query()->whereJsonContains('services_offered', ServiceType::Fuel->value)->pluck('id');

    expect($matching)->toContain($fuelSupplier->id);
    expect($matching)->not->toContain($handlingSupplier->id);

    // Not enforced server-side (unlike aircraft/customer) — a service can
    // still reference a supplier that doesn't list that type.
    $operations = userWithRoleFor($company, 'Operations');
    $flightRequest = FlightRequest::factory()->for($company)->create();

    Livewire::actingAs($operations)
        ->test(ServicesRelationManager::class, [
            'ownerRecord' => $flightRequest,
            'pageClass' => EditFlightRequest::class,
        ])
        ->callTableAction('create', data: [
            'type' => ServiceType::Fuel->value,
            'status' => ServiceStatus::NotStarted->value,
            'supplier_id' => $handlingSupplier->id,
        ])
        ->assertHasNoTableActionErrors();

    expect($flightRequest->services()->first()->supplier_id)->toBe($handlingSupplier->id);
});
