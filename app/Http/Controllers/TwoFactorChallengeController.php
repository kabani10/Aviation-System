<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Actions\TwoFactor\VerifyTwoFactorChallenge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The post-login checkpoint for users with 2FA enabled. Reached only via
 * EnsureTwoFactorChallengeCompleted redirecting an already-authenticated
 * session here — this route itself doesn't check 2FA state, it resolves it.
 */
class TwoFactorChallengeController extends Controller
{
    public function create(): View
    {
        return view('auth.two-factor-challenge');
    }

    public function store(Request $request, VerifyTwoFactorChallenge $verify): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        if (! $verify($request->user(), $request->string('code')->value())) {
            return back()->withErrors(['code' => 'That code is invalid or has expired.']);
        }

        $request->session()->put('2fa_passed', true);
        $request->session()->regenerate();

        return redirect()->intended('/admin');
    }
}
