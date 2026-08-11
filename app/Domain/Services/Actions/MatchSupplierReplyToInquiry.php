<?php

namespace App\Domain\Services\Actions;

use App\Domain\Communications\Models\Communication;
use App\Domain\Services\Enums\SupplierInquiryStatus;
use App\Domain\Services\Models\SupplierInquiry;
use App\Domain\Suppliers\Models\SupplierContact;
use Illuminate\Support\Str;

/**
 * Deterministic, not AI — matching an inbound email to the inquiry it's
 * replying about is a lookup (who sent it, what's still open), not
 * something ambiguous for a model to resolve; same "app/AI exists to
 * isolate a Claude-API failure mode, and there's no such failure mode here"
 * reasoning CheckMissingInformation already applies. Matches by the
 * sender's email against SupplierContact.email, case-insensitively —
 * Postmark's From is already a plain address (see ReceiveInboundEmail), no
 * "Name <email>" parsing needed — scoped to the current tenant like
 * everything else via CompanyScope.
 *
 * Only ever returns a match when there's exactly one open (Sent, no
 * response yet) inquiry for that contact — same "leaving it unmatched is
 * always safer than guessing wrong" principle
 * CreateFlightRequestFromExtraction already applies to dates. A contact
 * juggling two simultaneous RFQs makes "which one is this reply about" a
 * real ambiguity worth leaving to a human via the ordinary "Record
 * response" action, not a subject-line heuristic that could guess wrong.
 */
class MatchSupplierReplyToInquiry
{
    public function __invoke(Communication $communication): ?SupplierInquiry
    {
        if (! $communication->from_address) {
            return null;
        }

        $contactIds = SupplierContact::query()
            ->whereRaw('lower(email) = ?', [Str::lower($communication->from_address)])
            ->pluck('id');

        if ($contactIds->isEmpty()) {
            return null;
        }

        $openInquiries = SupplierInquiry::query()
            ->whereIn('supplier_contact_id', $contactIds)
            ->where('status', SupplierInquiryStatus::Sent)
            ->get();

        return $openInquiries->count() === 1 ? $openInquiries->first() : null;
    }
}
