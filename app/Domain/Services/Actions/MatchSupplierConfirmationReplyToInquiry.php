<?php

namespace App\Domain\Services\Actions;

use App\Domain\Communications\Models\Communication;
use App\Domain\Services\Enums\SupplierInquiryStatus;
use App\Domain\Services\Models\SupplierInquiry;
use App\Domain\Suppliers\Models\SupplierContact;
use Illuminate\Support\Str;

/**
 * The confirmation-cycle sibling of MatchSupplierReplyToInquiry — same
 * deterministic, case-insensitive sender-email matching, same "exactly one
 * open candidate or don't guess" rule, but scoped to inquiries actually
 * awaiting a confirmation reply (Chosen, confirmation sent, not yet
 * confirmed) rather than ones awaiting a first price quote (Sent). Kept as
 * its own class rather than a parameter on the other matcher — "which
 * open thing is this reply about" is a different question at each stage of
 * an inquiry's life, and conflating the two risks matching a price reply
 * against a confirmation-stage inquiry or vice versa.
 */
class MatchSupplierConfirmationReplyToInquiry
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

        $awaitingConfirmation = SupplierInquiry::query()
            ->whereIn('supplier_contact_id', $contactIds)
            ->where('status', SupplierInquiryStatus::Chosen)
            ->whereNotNull('confirmation_requested_at')
            ->whereNull('confirmed_at')
            ->get();

        return $awaitingConfirmation->count() === 1 ? $awaitingConfirmation->first() : null;
    }
}
