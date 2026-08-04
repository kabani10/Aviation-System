<?php

use App\Domain\ReferenceData\Models\Airport;
use App\Domain\Shared\Enums\ServiceType;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\Suppliers\SupplierResource\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\SupplierResource\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\SupplierResource\RelationManagers\ContactsRelationManager;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;

it('lets Procurement create a supplier with services and airports covered', function () {
    $company = Company::factory()->create();
    $procurement = userWithRoleFor($company, 'Procurement');
    app(CurrentCompany::class)->set($company->id);

    $dxb = Airport::where('icao_code', 'OMDB')->first();

    Livewire::actingAs($procurement)
        ->test(CreateSupplier::class)
        ->fillForm([
            'name' => 'Gulf Ground Services',
            'currency' => 'aed',
            'payment_terms' => 'Net 15',
            'services_offered' => [ServiceType::GroundHandling->value, ServiceType::Fuel->value],
            'airports' => [$dxb->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $supplier = $company->fresh()->suppliers()->first();

    expect($supplier)->not->toBeNull();
    expect($supplier->currency)->toBe('AED');
    expect($supplier->serviceTypes())->toEqual([ServiceType::GroundHandling, ServiceType::Fuel]);
    expect($supplier->airports()->pluck('icao_code'))->toContain('OMDB');
});

it('lets a view-only role (Operations) see suppliers but not create one', function () {
    $company = Company::factory()->create();
    $operations = userWithRoleFor($company, 'Operations');

    $this->actingAs($operations)->get('/admin/suppliers')->assertOk();
    $this->actingAs($operations)->get('/admin/suppliers/create')->assertForbidden();
});

it('does not let a role with no suppliers permission at all see the list', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);

    $this->actingAs($sales)->get('/admin/suppliers')->assertForbidden();
});

it('never shows one company\'s suppliers to another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CurrentCompany::class)->set($companyA->id);
    Supplier::factory()->for($companyA)->create(['name' => 'Company A Only Supplier']);

    $procurementB = userWithRoleFor($companyB, 'Procurement');

    $this->actingAs($procurementB)
        ->get('/admin/suppliers')
        ->assertOk()
        ->assertDontSee('Company A Only Supplier');
});

it('adds a contact to a supplier via the relation manager', function () {
    $company = Company::factory()->create();
    $procurement = userWithRoleFor($company, 'Procurement');
    app(CurrentCompany::class)->set($company->id);

    $supplier = Supplier::factory()->for($company)->create();

    Livewire::actingAs($procurement)
        ->test(ContactsRelationManager::class, [
            'ownerRecord' => $supplier,
            'pageClass' => EditSupplier::class,
        ])
        ->callTableAction('create', data: [
            'name' => 'Ahmed Fuel Desk',
            'email' => 'ahmed@supplier.test',
        ])
        ->assertHasNoTableActionErrors();

    $contact = $supplier->contacts()->first();

    expect($contact)->not->toBeNull();
    expect($contact->name)->toBe('Ahmed Fuel Desk');
    expect($contact->company_id)->toBe($company->id);
});
