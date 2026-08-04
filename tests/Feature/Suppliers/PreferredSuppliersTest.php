<?php

use App\Domain\Customers\Models\Customer;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\Customers\CustomerResource\Pages\EditCustomer;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;

it('lets Sales attach preferred suppliers to a customer', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create();
    $supplierA = Supplier::factory()->for($company)->create(['name' => 'Preferred Fuel Co']);
    $supplierB = Supplier::factory()->for($company)->create(['name' => 'Backup Handling Co']);

    Livewire::actingAs($sales)
        ->test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->fillForm(['preferredSuppliers' => [$supplierA->id, $supplierB->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    $preferred = $customer->fresh()->preferredSuppliers()->pluck('name');

    expect($preferred)->toContain('Preferred Fuel Co', 'Backup Handling Co');
});

it('cannot attach another company\'s supplier as preferred, even by submitting its id directly', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CurrentCompany::class)->set($companyA->id);
    $customerA = Customer::factory()->for($companyA)->create();

    app(CurrentCompany::class)->set($companyB->id);
    $supplierB = Supplier::factory()->for($companyB)->create(['name' => 'Company B Supplier']);

    app(CurrentCompany::class)->set($companyA->id);
    $salesA = salesUserFor($companyA);

    // Simulates a manipulated request rather than a normal UI select —
    // the option would never appear in company A's own picker.
    Livewire::actingAs($salesA)
        ->test(EditCustomer::class, ['record' => $customerA->getRouteKey()])
        ->fillForm(['preferredSuppliers' => [$supplierB->id]])
        ->call('save');

    expect($customerA->fresh()->preferredSuppliers()->pluck('name'))->not->toContain('Company B Supplier');
});
