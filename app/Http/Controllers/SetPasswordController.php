<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * Where an invited employee's set-password email link lands — the other
 * half of InviteEmployee, which creates the account with an unusable
 * password and sends this link instead of ever knowing a real one.
 */
class SetPasswordController extends Controller
{
    public function create(string $token): View
    {
        return view('auth.set-password', [
            'token' => $token,
            'email' => request('email', ''),
        ]);
    }

    public function store(SetPasswordRequest $request): RedirectResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();

                event(new PasswordReset($user));

                Auth::login($user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __($status)]);
        }

        $request->session()->regenerate();

        return redirect()->intended('/admin');
    }
}
