<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Actions\RegisterCompany;
use App\Http\Requests\RegisterCompanyRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class CompanyRegistrationController extends Controller
{
    public function create(): View
    {
        return view('auth.register-company');
    }

    public function store(RegisterCompanyRequest $request, RegisterCompany $registerCompany): RedirectResponse
    {
        $user = $registerCompany(
            companyName: $request->string('company_name')->value(),
            adminName: $request->string('admin_name')->value(),
            adminEmail: $request->string('admin_email')->value(),
            password: $request->string('password')->value(),
        );

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/admin');
    }
}
