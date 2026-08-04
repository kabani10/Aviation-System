<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Models\Document;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only path a document's contents ever leave the server through.
 * CompanyScope should already exclude another tenant's document at the
 * route-model-binding stage (see bootstrap/app.php's priority ordering of
 * SetCurrentCompany vs SubstituteBindings) — but that's middleware order,
 * which is exactly the kind of thing that quietly breaks in a future
 * refactor. This check doesn't depend on it: it's the actual security
 * boundary, middleware ordering is just defense in depth on top of it.
 */
class DocumentDownloadController extends Controller
{
    public function __invoke(Document $document): StreamedResponse
    {
        abort_unless($document->company_id === Auth::user()->company_id, 404);

        return $document->download();
    }
}
