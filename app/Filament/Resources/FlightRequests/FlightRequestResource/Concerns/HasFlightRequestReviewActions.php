<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\Concerns;

use App\Domain\FlightRequests\Actions\CheckMissingInformation;
use App\Domain\FlightRequests\Actions\CheckOperationalRisks;
use App\Domain\FlightRequests\Models\FlightRequest;
use Filament\Actions\Action;
use Illuminate\Contracts\View\View;

/**
 * Shared between ViewFlightRequest and EditFlightRequest — all three
 * actions here are things an operator checks or confirms while reviewing a
 * flight request, and Filament resource pages don't share a common
 * ancestor worth hanging this on instead. Only "Mark AI draft reviewed" is
 * actually AI-related; Missing information and Operational risks are both
 * deterministic domain checks (see CheckMissingInformation and
 * CheckOperationalRisks for why) that happen to live in the same "things
 * you look at when reviewing a request" grouping — hence the neutral trait
 * name rather than "HasAiReviewActions".
 */
trait HasFlightRequestReviewActions
{
    /** @return array<int, Action> */
    protected function flightRequestReviewHeaderActions(): array
    {
        return [
            Action::make('missingInformation')
                ->label('Missing information')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->modalHeading('Missing information')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn (): View => view('filament.flight-requests.findings', [
                    'findings' => app(CheckMissingInformation::class)($this->getRecord()),
                    'emptyMessage' => 'No issues found — this flight request has everything currently checked for.',
                ])),

            Action::make('operationalRisks')
                ->label('Operational risks')
                ->icon('heroicon-o-shield-exclamation')
                ->color('danger')
                ->modalHeading('Operational risks')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn (): View => view('filament.flight-requests.findings', [
                    'findings' => app(CheckOperationalRisks::class)($this->getRecord()),
                    'emptyMessage' => "No operational risks found on this flight's services right now.",
                ])),

            Action::make('markReviewed')
                ->label('Mark AI draft reviewed')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->needsReview())
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
