<?php

use App\Domain\Customers\Models\Customer;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\Customers\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\Customers\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\Customers\CustomerResource\RelationManagers\ContactsRelationManager;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;

it('lets Sales create a customer through the panel', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    Livewire::actingAs($sales)
        ->test(CreateCustomer::class)
        ->fillForm([
            'name' => 'Acme Charter Broker',
            'billing_email' => 'billing@acmecharter.test',
            'payment_terms' => 'Net 30',
            'special_instructions' => 'Always confirm catering 24h ahead.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $customer = $company->fresh()->customers()->first();

    expect($customer)->not->toBeNull();
    expect($customer->name)->toBe('Acme Charter Broker');
    expect($customer->is_active)->toBeTrue();
    expect($customer->displayLabel())->toBe('Acme Charter Broker');
});

it('does not let a role without customers.view see the customer list', function () {
    $company = Company::factory()->create();
    $operations = User::factory()->for($company)->create();
    $operations->assignRole('Operations');

    $this->actingAs($operations)
        ->get('/admin/customers')
        ->assertForbidden();
});

it('never shows one company\'s customers to another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CurrentCompany::class)->set($companyA->id);
    Customer::factory()->for($companyA)->create(['name' => 'Company A Client']);

    $salesB = salesUserFor($companyB);

    $this->actingAs($salesB)
        ->get('/admin/customers')
        ->assertOk()
        ->assertDontSee('Company A Client');
});

it('adds a primary contact to a customer via the relation manager', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create();

    Livewire::actingAs($sales)
        ->test(ContactsRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => EditCustomer::class,
        ])
        ->callTableAction('create', data: [
            'name' => 'Jane Ops',
            'email' => 'jane@customer.test',
            'title' => 'Operations Manager',
            'is_primary' => true,
        ])
        ->assertHasNoTableActionErrors();

    $contact = $customer->contacts()->first();

    expect($contact)->not->toBeNull();
    expect($contact->name)->toBe('Jane Ops');
    expect($contact->is_primary)->toBeTrue();
    expect($contact->company_id)->toBe($company->id);
});
