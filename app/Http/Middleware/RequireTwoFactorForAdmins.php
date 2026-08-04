<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin bypasses every permission check (see Gate::before in
 * AppServiceProvider), which is exactly why it's the one role that can't be
 * allowed to skip 2FA — there's no lesser-privileged fallback to catch a
 * compromised Admin account.
 */
class RequireTwoFactorForAdmins
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $onSetupPage = $request->routeIs('filament.admin.pages.two-factor-authentication');

        if ($user?->hasRole('Admin') && ! $user->hasEnabledTwoFactorAuthentication() && ! $onSetupPage) {
            return redirect()->route('filament.admin.pages.two-factor-authentication');
        }

        return $next($request);
    }
}
