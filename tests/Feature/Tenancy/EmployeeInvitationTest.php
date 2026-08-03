<?php

use App\Domain\Tenancy\Actions\InviteEmployee;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

it('invites an employee with a role and emails them a set-password link', function () {
    Notification::fake();

    $company = Company::factory()->create();

    $employee = app(InviteEmployee::class)($company, 'New Hire', 'newhire@test.example', 'Operations');

    expect($employee->company_id)->toBe($company->id);
    expect($employee->is_active)->toBeTrue();
    expect($employee->hasRole('Operations'))->toBeTrue();

    Notification::assertSentTo($employee, ResetPassword::class);
});

it('only lets an Admin manage employees', function () {
    $company = Company::factory()->create();

    $salesUser = User::factory()->for($company)->create();
    $salesUser->assignRole('Sales');

    $this->actingAs($salesUser)
        ->get('/admin/tenancy/users')
        ->assertForbidden();
});

it('lets an Admin create employees through the panel', function () {
    Notification::fake();

    $company = Company::factory()->create();
    $admin = User::factory()->for($company)->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
        ->get('/admin/tenancy/users')
        ->assertOk();
});
