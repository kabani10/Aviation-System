<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user with 2FA enabled is fully Auth::login()'d after password check —
 * Filament's own login flow doesn't have a "not quite logged in yet" state
 * to hook into. Instead, this middleware gates everything else in the panel
 * behind session('2fa_passed') until the challenge route flips it to true.
 * That session key can only be set server-side (routes/web.php's
 * two-factor challenge routes), never by the client.
 */
class EnsureTwoFactorChallengeCompleted
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->hasEnabledTwoFactorAuthentication() && ! session('2fa_passed', false)) {
            return redirect()->route('two-factor.challenge');
        }

        return $next($request);
    }
}
