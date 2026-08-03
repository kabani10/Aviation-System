<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated user's company as the tenant scope for this
 * request. Queued jobs don't go through HTTP middleware — they must set
 * CurrentCompany explicitly from the model they were dispatched for.
 */
class SetCurrentCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            app(CurrentCompany::class)->set($user->company_id);
        }

        return $next($request);
    }
}
