<?php

use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\Customers\Models\Customer;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\Aircraft\AircraftResource\Pages\CreateAircraft;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

it('lets Sales register an aircraft against a customer', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create();

    Livewire::actingAs($sales)
        ->test(CreateAircraft::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'registration' => 'N650GS',
            'aircraft_type' => 'Gulfstream G650',
            'mtow_kg' => 41277,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $aircraft = $customer->aircraft()->first();

    expect($aircraft)->not->toBeNull();
    expect($aircraft->registration)->toBe('N650GS');
    expect($aircraft->mtow_kg)->toBe(41277);
    expect($aircraft->company_id)->toBe($company->id);
    expect($aircraft->displayLabel())->toBe('N650GS (Gulfstream G650)');
});

it('rejects a second aircraft with the same registration in the same company', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);
    $customer = Customer::factory()->for($company)->create();

    Aircraft::factory()->for($customer)->create(['registration' => 'N650GS']);

    expect(fn () => Aircraft::factory()->for($customer)->create(['registration' => 'N650GS']))
        ->toThrow(QueryException::class);
});

it('allows the same registration to exist in two different companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CurrentCompany::class)->set($companyA->id);
    $customerA = Customer::factory()->for($companyA)->create();
    Aircraft::factory()->for($customerA)->create(['registration' => 'N650GS']);

    app(CurrentCompany::class)->set($companyB->id);
    $customerB = Customer::factory()->for($companyB)->create();
    $aircraftB = Aircraft::factory()->for($customerB)->create(['registration' => 'N650GS']);

    expect($aircraftB->registration)->toBe('N650GS');
});

it('never shows one company\'s fleet to another company on the standalone Aircraft page', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CurrentCompany::class)->set($companyA->id);
    $customerA = Customer::factory()->for($companyA)->create();
    Aircraft::factory()->for($customerA)->create(['registration' => 'N-ONLY-A']);

    $salesB = salesUserFor($companyB);

    $this->actingAs($salesB)
        ->get('/admin/aircraft')
        ->assertOk()
        ->assertDontSee('N-ONLY-A');
});
