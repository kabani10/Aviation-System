<?php

use App\Domain\Tenancy\Actions\InviteEmployee;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

it('logs company field changes', function () {
    $company = Company::factory()->create(['name' => 'Original Name']);

    $company->update(['name' => 'Renamed Co']);

    $log = Activity::query()
        ->where('subject_type', Company::class)
        ->where('subject_id', $company->id)
        ->where('event', 'updated')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->attribute_changes['attributes']['name'])->toBe('Renamed Co');
});

it('logs user field changes but never the password or 2FA secret', function () {
    $user = User::factory()->for(Company::factory())->create(['name' => 'Original']);

    $user->update(['name' => 'Updated', 'password' => 'a-new-password']);

    $log = Activity::query()
        ->where('subject_type', User::class)
        ->where('subject_id', $user->id)
        ->where('event', 'updated')
        ->first();

    expect($log->attribute_changes['attributes'])->toHaveKey('name');
    expect($log->attribute_changes['attributes'])->not->toHaveKey('password');
    expect($log->attribute_changes['attributes'])->not->toHaveKey('two_factor_secret');
});

it('logs an explicit event when an employee is invited with a role', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->for($company)->create();
    $this->actingAs($admin);

    $employee = app(InviteEmployee::class)($company, 'New Hire', 'newhire@test.example', 'Operations');

    $log = Activity::query()->where('description', 'employee_invited')->where('subject_id', $employee->id)->first();

    expect($log)->not->toBeNull();
    expect($log->properties['role'])->toBe('Operations');
});
