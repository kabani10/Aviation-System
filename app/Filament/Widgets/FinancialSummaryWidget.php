<?php

namespace App\Filament\Widgets;

use App\Domain\Finance\Actions\ComputeFinancialSummary;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * The dashboard face of ComputeFinancialSummary. Gated on reports.view to
 * appear at all, then the revenue-shaped stats additionally need
 * finance.view_prices and the margin stat additionally needs
 * finance.view_costs — same field-level-on-top-of-screen-level gating
 * every other financial figure in this app uses, even though in practice
 * every current reports.view holder (Finance, Management) already has
 * both.
 */
class FinancialSummaryWidget extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return Auth::user()->can('reports.view');
    }

    protected function getStats(): array
    {
        $summary = app(ComputeFinancialSummary::class)();

        $stats = [];

        if (Auth::user()->can('finance.view_prices')) {
            $stats[] = Stat::make('Total invoiced', '$'.number_format($summary->totalInvoiced, 2));
            $stats[] = Stat::make('Collected', '$'.number_format($summary->totalCollected, 2));
            $stats[] = Stat::make('Outstanding', '$'.number_format($summary->totalOutstanding, 2));
            $stats[] = Stat::make('Overdue invoices', "{$summary->overdueCount} (\$".number_format($summary->overdueAmount, 2).')')
                ->color($summary->overdueCount > 0 ? 'danger' : 'success');
        }

        if (Auth::user()->can('finance.view_costs')) {
            $stats[] = Stat::make(
                'Profit margin (collected)',
                $summary->totalProfitMargin !== null ? '$'.number_format($summary->totalProfitMargin, 2) : '—',
            );
        }

        return $stats;
    }
}
