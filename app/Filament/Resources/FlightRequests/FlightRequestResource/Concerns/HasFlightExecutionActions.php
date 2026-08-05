<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\Concerns;

use App\Domain\FlightRequests\Actions\CheckFlightReadiness;
use App\Domain\FlightRequests\Actions\CompleteFlight;
use App\Domain\FlightRequests\Actions\MarkFlightInOperation;
use App\Domain\FlightRequests\Enums\FlightStatus;
use Filament\Actions\Action;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * The Confirmed → InOperation → Completed leg of FlightStatus — same
 * "close the gap on an enum case that's had no action behind it since it
 * was defined" pattern as ServicesRelationManager's quote actions (Phase 8)
 * and QuotationsRelationManager (Phase 10). Kept as its own trait rather
 * than folded into HasFlightRequestReviewActions: these mutate the
 * flight's status forward, the review actions are checks/confirmations —
 * different enough intents that sharing one trait would blur the line.
 *
 * Both actions reuse CheckFlightReadiness's findings in their confirmation
 * modal, but never block on them — see that class's docblock for why.
 */
trait HasFlightExecutionActions
{
    /** @return array<int, Action> */
    protected function flightExecutionHeaderActions(): array
    {
        return [
            Action::make('markInOperation')
                ->label('Mark in operation')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->visible(fn (): bool => Auth::user()->can('flights.manage') && $this->getRecord()->status === FlightStatus::Confirmed)
                ->requiresConfirmation()
                ->modalHeading('Mark flight in operation')
                ->modalContent(fn (): View => view('filament.flight-requests.findings', [
                    'findings' => app(CheckFlightReadiness::class)($this->getRecord()),
                    'emptyMessage' => 'No readiness issues found.',
                ]))
                ->action(fn () => app(MarkFlightInOperation::class)($this->getRecord()))
                ->successNotificationTitle('Flight marked in operation'),

            Action::make('markCompleted')
                ->label('Mark completed')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (): bool => Auth::user()->can('flights.manage') && $this->getRecord()->status === FlightStatus::InOperation)
                ->requiresConfirmation()
                ->modalHeading('Mark flight completed')
                ->modalContent(fn (): View => view('filament.flight-requests.findings', [
                    'findings' => app(CheckFlightReadiness::class)($this->getRecord()),
                    'emptyMessage' => 'No readiness issues found.',
                ]))
                ->action(fn () => app(CompleteFlight::class)($this->getRecord()))
                ->successNotificationTitle('Flight marked completed'),
        ];
    }
}
