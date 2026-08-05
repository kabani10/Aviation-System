<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\DataTransferObjects\FinancialSummary;
use App\Domain\Finance\Enums\InvoiceStatus;
use App\Domain\Finance\Models\Invoice;

/**
 * The spec's "financial reports" — what Management's reports.view
 * permission (unused since Phase 1) finally has something to show, via
 * FinancialSummaryWidget. Deterministic, like every other reporting/check
 * action in this app: this is arithmetic over real invoice data, not
 * something needing AI. Implicitly scoped to the current company through
 * Invoice's own CompanyScope, same as every other in-panel query — no
 * explicit Company param, since this only ever runs inside an
 * already-tenant-scoped request.
 */
class ComputeFinancialSummary
{
    public function __invoke(): FinancialSummary
    {
        $invoices = Invoice::query()->with('quotation.lineItems')->get();

        $sentOrPaid = $invoices->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Paid]);
        $paid = $invoices->where('status', InvoiceStatus::Paid);
        $outstanding = $invoices->where('status', InvoiceStatus::Sent);
        $overdue = $outstanding->filter(fn (Invoice $invoice): bool => $invoice->isOverdue());

        $margins = $paid
            ->map(fn (Invoice $invoice): ?float => $invoice->profitMargin())
            ->filter(fn (?float $margin): bool => $margin !== null);

        return new FinancialSummary(
            totalInvoiced: (float) $sentOrPaid->sum(fn (Invoice $invoice): float => $invoice->totalAmount()),
            totalCollected: (float) $paid->sum(fn (Invoice $invoice): float => $invoice->totalAmount()),
            totalOutstanding: (float) $outstanding->sum(fn (Invoice $invoice): float => $invoice->totalAmount()),
            overdueCount: $overdue->count(),
            overdueAmount: (float) $overdue->sum(fn (Invoice $invoice): float => $invoice->totalAmount()),
            totalProfitMargin: $margins->isEmpty() ? null : (float) $margins->sum(),
        );
    }
}
