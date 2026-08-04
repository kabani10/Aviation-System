<?php

use App\Domain\Tenancy\Models\Company;
use App\Filament\Pages\TwoFactorAuthentication;
use App\Models\User;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

it('forces an unconfirmed Admin to the setup page instead of the dashboard', function () {
    $admin = User::factory()->for(Company::factory())->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
        ->get('/admin')
        ->assertRedirect('/admin/two-factor-authentication');
});

it('does not force 2FA setup on non-admin roles', function () {
    $sales = User::factory()->for(Company::factory())->create();
    $sales->assignRole('Sales');

    $this->actingAs($sales)
        ->get('/admin')
        ->assertOk();
});

it('completes setup, confirming with a valid TOTP code and issuing recovery codes', function () {
    $user = User::factory()->for(Company::factory())->create();

    $component = Livewire::actingAs($user)->test(TwoFactorAuthentication::class);
    $component->call('startSetup');

    $secret = $component->get('pendingSecret');
    expect($secret)->not->toBeEmpty();

    $validCode = app(Google2FA::class)->getCurrentOtp($secret);

    $component->set('code', $validCode)->call('confirmSetup');

    $component->assertSet('justConfirmed', true);
    expect($component->get('recoveryCodes'))->toHaveCount(8);

    $user->refresh();
    expect($user->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

it('rejects an invalid code during setup confirmation', function () {
    $user = User::factory()->for(Company::factory())->create();

    $component = Livewire::actingAs($user)->test(TwoFactorAuthentication::class);
    $component->call('startSetup')
        ->set('code', '000000')
        ->call('confirmSetup')
        ->assertHasErrors('code');

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

it('disables 2FA only with the correct current password', function () {
    $user = User::factory()->for(Company::factory())->withTwoFactorConfirmed()->create();

    $component = Livewire::actingAs($user)->test(TwoFactorAuthentication::class);

    $component->set('currentPassword', 'wrong-password')->call('disable');
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();

    $component->set('currentPassword', 'password')->call('disable');
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

it('redirects a 2FA-enabled user to the challenge before they reach the panel', function () {
    $user = User::factory()->for(Company::factory())->withTwoFactorConfirmed()->create();

    $this->actingAs($user)
        ->get('/admin')
        ->assertRedirect('/two-factor-challenge');
});

it('lets a real TOTP code clear the challenge and reach the panel', function () {
    $user = User::factory()->for(Company::factory())->create();
    // Real (undecorated) secret, confirmed the normal way.
    $secret = app(Google2FA::class)->generateSecretKey();
    $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

    $response = $this->actingAs($user)->post('/two-factor-challenge', [
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ]);

    $response->assertRedirect('/admin');
    expect(session('2fa_passed'))->toBeTrue();
});

it('rejects a bad code at the challenge and does not clear it', function () {
    $user = User::factory()->for(Company::factory())->withTwoFactorConfirmed()->create();

    $response = $this->actingAs($user)->post('/two-factor-challenge', ['code' => '000000']);

    $response->assertSessionHasErrors('code');
    expect(session('2fa_passed', false))->toBeFalse();
});

it('accepts a recovery code once and refuses it on reuse', function () {
    $user = User::factory()->for(Company::factory())->create();
    $secret = app(Google2FA::class)->generateSecretKey();
    $recoveryCode = 'test-recovery-code-abc123';

    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_recovery_codes' => [$recoveryCode, 'another-code'],
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($user)
        ->post('/two-factor-challenge', ['code' => $recoveryCode])
        ->assertRedirect('/admin');

    expect($user->fresh()->two_factor_recovery_codes)->not->toContain($recoveryCode);

    // Log the challenge back in (a fresh request re-checks the session flag)
    // and confirm the same code no longer works.
    session(['2fa_passed' => false]);

    $this->actingAs($user)
        ->post('/two-factor-challenge', ['code' => $recoveryCode])
        ->assertSessionHasErrors('code');
});

it('clears the 2FA challenge flag on every fresh login, defending against session fixation', function () {
    $user = User::factory()->for(Company::factory())->withTwoFactorConfirmed()->create();

    session(['2fa_passed' => true]);

    auth()->login($user);

    expect(session('2fa_passed', false))->toBeFalse();
});
