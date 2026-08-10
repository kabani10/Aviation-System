<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\Concerns;

use App\Domain\FlightRequests\Models\FlightRequest;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;

/**
 * Shared between ViewFlightRequest and EditFlightRequest. Missing
 * information and Operational risks used to have header actions here too
 * (modal views over CheckMissingInformation/CheckOperationalRisks) — removed
 * from this page at the user's request; both domain checks still run as
 * part of the daily digest (BuildFlightRequestDigest), this only removed the
 * on-page buttons. "Mark AI draft reviewed" is the one review action left.
 */
trait HasFlightRequestReviewActions
{
    /** @return array<int, Action> */
    protected function flightRequestReviewHeaderActions(): array
    {
        return [
            Action::make('markReviewed')
                ->label('Mark AI draft reviewed')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                // flights.manage, not just visible-on-the-view-page: without
                // this a view-only role (Procurement/Finance/Management, who
                // all reach ViewFlightRequest on flights.view alone — see
                // Service Management's "ViewRecord page" note) could mark an
                // AI draft reviewed despite having no edit rights on the
                // flight at all. Found while wiring the same gate onto
                // Phase 11's execution actions below.
                ->visible(fn (): bool => Auth::user()->can('flights.manage') && $this->getRecord()->needsReview())
                ->requiresConfirmation()
                ->modalDescription('Confirms this AI-drafted flight request has been checked and corrected as needed.')
                ->action(function (): void {
                    /** @var FlightRequest $record */
                    $record = $this->getRecord();
                    $record->update(['reviewed_at' => now()]);
                }),
        ];
    }
}
