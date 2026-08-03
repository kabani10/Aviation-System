<?php

use App\Domain\Tenancy\Models\Company;
use App\Models\User;

it('creates a company and its admin user, and logs them in', function () {
    $response = $this->post('/register', [
        'company_name' => 'Acme Aviation',
        'admin_name' => 'Jane Admin',
        'admin_email' => 'jane@acme.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $company = Company::where('name', 'Acme Aviation')->first();
    $user = User::where('email', 'jane@acme.test')->first();

    expect($company)->not->toBeNull();
    expect($user)->not->toBeNull();
    expect($user->company_id)->toBe($company->id);
    expect($user->hasRole('Admin'))->toBeTrue();

    $response->assertRedirect('/admin');
    $this->assertAuthenticatedAs($user);
});

it('rejects registration with a duplicate email', function () {
    User::factory()->create(['email' => 'taken@acme.test']);

    $response = $this->post('/register', [
        'company_name' => 'Another Aviation Co',
        'admin_name' => 'Someone',
        'admin_email' => 'taken@acme.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertSessionHasErrors('admin_email');
});

it('rejects registration when passwords do not match', function () {
    $response = $this->post('/register', [
        'company_name' => 'Acme Aviation',
        'admin_name' => 'Jane Admin',
        'admin_email' => 'jane@acme.test',
        'password' => 'password123',
        'password_confirmation' => 'not-the-same',
    ]);

    $response->assertSessionHasErrors('password');
    expect(User::where('email', 'jane@acme.test')->exists())->toBeFalse();
});
