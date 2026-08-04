<?php

use App\Http\Controllers\CompanyRegistrationController;
use App\Http\Controllers\SetPasswordController;
use App\Http\Controllers\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/register', [CompanyRegistrationController::class, 'create'])->name('register-company');
Route::post('/register', [CompanyRegistrationController::class, 'store'])->name('register-company.store');

// 'password.reset' is the name Laravel's password broker generates invite
// and forgot-password links with — see InviteEmployee and SetPasswordController.
Route::get('/set-password/{token}', [SetPasswordController::class, 'create'])->name('password.reset');
Route::post('/set-password', [SetPasswordController::class, 'store'])->name('password.store');

// Reachable only by an already-authenticated session that hasn't cleared
// the 2FA challenge yet — see EnsureTwoFactorChallengeCompleted.
Route::middleware('auth')->group(function () {
    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])
        ->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->name('two-factor.challenge.store');
});
