<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\Invoice;
use App\Models\User;

/**
 * No dedicated invoices.* permission — unlike Quotation, there was no
 * unused invoices.* pair sitting in RolesAndPermissionsSeeder waiting for
 * this module (quotations.* was there since Phase 1; nothing equivalent
 * was ever added for invoices). finance.view_prices already governs who
 * can see a selling price, and an invoice amount is exactly that, so
 * viewing reuses it — same reasoning Sales already relies on for
 * Quotation. Creating/sending/recording payment is finance.manage, since
 * invoicing itself is Finance's job per the spec, not Sales's.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('finance.view_prices');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can('finance.view_prices');
    }

    public function create(User $user): bool
    {
        return $user->can('finance.manage');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->can('finance.manage');
    }

    /** No hard delete — same "history stays" convention as Quotation. */
    public function delete(User $user, Invoice $invoice): bool
    {
        return false;
    }
}
