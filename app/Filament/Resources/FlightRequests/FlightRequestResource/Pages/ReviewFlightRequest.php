<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\Pages;

use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Communications\Models\Communication;
use App\Filament\Resources\FlightRequests\FlightRequestResource;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Concerns\HasFlightRequestReviewActions;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Widgets\FlightItineraryOverview;
use Filament\Resources\Pages\EditRecord;

/**
 * The spec's "operator reviews the AI draft, approves or corrects" screen —
 * the same editable fields as EditFlightRequest, but next to the email that
 * produced the draft, so correcting it doesn't need a detour through the
 * Communications tab or Mailpit. Reached from the "Review draft" row action
 * on the list (see FlightRequestResource::table()), which only appears
 * while needsReview() is true — this page itself isn't gated on that, since
 * "Mark AI draft reviewed" already hides its own action once reviewed_at is
 * set, and there's no harm in a plain edit form being reachable here after.
 * Saving corrections and confirming the draft stay two separate actions —
 * the ordinary form Save button, and "Mark AI draft reviewed" from
 * HasFlightRequestReviewActions — exactly the pair EditFlightRequest already
 * offers, not a new combined one, so the two pages behave identically once
 * an operator is looking at either of them.
 */
class ReviewFlightRequest extends EditRecord
{
    use HasFlightRequestReviewActions;

    protected static string $resource = FlightRequestResource::class;

    protected static string $view = 'filament.flight-requests.pages.review-ai-draft';

    protected function getHeaderActions(): array
    {
        return [
            ...parent::getHeaderActions(),
            ...$this->flightRequestReviewHeaderActions(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            FlightItineraryOverview::class,
        ];
    }

    public function getTitle(): string
    {
        return 'Review AI Draft';
    }

    /**
     * The email ExtractFlightRequestFromEmail actually drafted this flight
     * from — CreateFlightRequestFromExtraction moves it onto the flight, so
     * it's the earliest EmailIn in the timeline, not just the latest one
     * (a flight can pick up later correspondence before it's reviewed).
     * ->reorder() first: HasCommunications orders this relation
     * ->latest('occurred_at') by default, and ->oldest() on top of that
     * would just add a second, losing ORDER BY clause rather than replace it.
     */
    public function getSourceEmail(): ?Communication
    {
        return $this->getRecord()->communications()
            ->where('type', CommunicationType::EmailIn)
            ->reorder('occurred_at')
            ->first();
    }
}
