<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\Concerns;

use App\Domain\FlightRequests\Actions\CheckMissingInformation;
use App\Domain\FlightRequests\Models\FlightRequest;
use Filament\Actions\Action;
use Illuminate\Contracts\View\View;

/**
 * Shared between ViewFlightRequest and EditFlightRequest — both need the
 * same "what's missing" and "mark this AI draft reviewed" actions, and
 * Filament resource pages don't share a common ancestor worth hanging this
 * on instead.
 */
trait HasAiReviewActions
{
    /** @return array<int, Action> */
    protected function aiReviewHeaderActions(): array
    {
        return [
            Action::make('missingInformation')
                ->label('Missing information')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->modalHeading('Missing information')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn (): View => view('filament.flight-requests.missing-information', [
                    'findings' => app(CheckMissingInformation::class)($this->getRecord()),
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
