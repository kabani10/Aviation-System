<?php

use App\Domain\Tenancy\Actions\InviteEmployee;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
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

it('lets an invited employee follow the emailed link to set their password and log in', function () {
    Notification::fake();

    $company = Company::factory()->create();
    $employee = app(InviteEmployee::class)($company, 'New Hire', 'newhire@test.example', 'Operations');

    $notificationToken = null;
    Notification::assertSentTo($employee, ResetPassword::class, function ($notification) use (&$notificationToken) {
        $notificationToken = $notification->token;

        return true;
    });

    $response = $this->post('/set-password', [
        'token' => $notificationToken,
        'email' => $employee->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ]);

    $response->assertRedirect('/admin');
    $this->assertAuthenticatedAs($employee);
    expect(Hash::check('a-brand-new-password', $employee->fresh()->password))->toBeTrue();
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
    $admin = User::factory()->for($company)->withTwoFactorConfirmed()->create();
    $admin->assignRole('Admin');

    $this->withSession(['2fa_passed' => true])
        ->actingAs($admin)
        ->get('/admin/tenancy/users')
        ->assertOk();
});
