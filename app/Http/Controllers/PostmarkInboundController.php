<?php

namespace App\Http\Controllers;

use App\Domain\Communications\Actions\ReceiveInboundEmail;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * routes/api.php carries the 'api' middleware group, not 'web' — there's no
 * session and SetCurrentCompany never runs, so CurrentCompany is set here
 * explicitly, same as the convention documented in ARCHITECTURE.md for
 * queued jobs. This is also why tenancy for this endpoint can't rely on
 * "who's logged in" — the company is resolved from the URL instead, and the
 * shared secret is what stands in for authentication.
 */
class PostmarkInboundController extends Controller
{
    public function __invoke(Request $request, Company $company, ReceiveInboundEmail $receive): JsonResponse
    {
        if (! $this->hasValidToken($request)) {
            return response()->json(['message' => 'Invalid token'], Response::HTTP_FORBIDDEN);
        }

        app(CurrentCompany::class)->set($company->id);

        $communication = $receive($company, $request->all());

        return response()->json(['communication_id' => $communication->id]);
    }

    private function hasValidToken(Request $request): bool
    {
        $expected = config('services.postmark.inbound_secret');

        if (! $expected) {
            return false;
        }

        return hash_equals($expected, (string) $request->query('token'));
    }
}
