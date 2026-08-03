<?php

use App\Http\Controllers\CompanyRegistrationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/register', [CompanyRegistrationController::class, 'create'])->name('register-company');
Route::post('/register', [CompanyRegistrationController::class, 'store'])->name('register-company.store');
