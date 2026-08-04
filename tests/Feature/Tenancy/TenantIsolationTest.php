<?php

use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;

it('never returns another company\'s users when scoped', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::factory()->for($companyA)->create();
    $userB = User::factory()->for($companyB)->create();

    app(CurrentCompany::class)->set($companyA->id);

    $visible = User::pluck('id');

    expect($visible)->toContain($userA->id);
    expect($visible)->not->toContain($userB->id);
});

it('cannot see another company\'s employees through the admin panel', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $adminA = User::factory()->for($companyA)->withTwoFactorConfirmed()->create();
    $adminA->assignRole('Admin');

    $employeeB = User::factory()->for($companyB)->create(['name' => 'Employee Of Company B']);
    $employeeB->assignRole('Operations');

    $response = $this->withSession(['2fa_passed' => true])->actingAs($adminA)->get('/admin/tenancy/users');

    $response->assertOk();
    $response->assertDontSee('Employee Of Company B');
});

it('scopes company_id automatically on create when a tenant context is active', function () {
    $company = Company::factory()->create();

    app(CurrentCompany::class)->set($company->id);

    $user = new User(['name' => 'Auto Scoped', 'email' => 'auto@test.example', 'password' => 'password']);
    $user->save();

    expect($user->company_id)->toBe($company->id);
});
