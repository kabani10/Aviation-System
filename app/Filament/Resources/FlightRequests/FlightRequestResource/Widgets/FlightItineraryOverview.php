<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\Widgets;

use App\Domain\FlightRequests\Actions\CheckFlightReadiness;
use App\Domain\FlightRequests\Actions\CheckFlightReadinessWarning;
use App\Domain\FlightRequests\DataTransferObjects\ReadinessIssue;
use App\Domain\FlightRequests\Models\FlightRequest;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The "see every leg, and what each one still needs" view the Legs and
 * Services tabs don't give you on their own — those are for editing one
 * thing at a time, this is for reading the whole itinerary at a glance
 * the moment the page opens. Read-only: every action here (request a
 * quote, mark a service confirmed, add a leg) still happens on the tabs
 * below, this widget just answers "what does leg 2 still need" without
 * clicking into a filtered services list to find out.
 *
 * Not in app/Filament/Widgets — that folder is auto-discovered onto the
 * Dashboard (see AdminPanelProvider), and this widget requires a specific
 * FlightRequest record it has no business rendering without.
 *
 * As of Phase 19, also the view page's home for the passive "departing soon
 * and not ready" banner — the same signal the list and kanban board show,
 * spelled out here with the actual CheckFlightReadiness findings since this
 * page has the room for them.
 */
class FlightItineraryOverview extends Widget
{
    protected static bool $isDiscovered = false;

    // Widgets lazy-load by default (Filament\Support\Concerns\CanBeLazy) —
    // fine for a dashboard chart below the fold, wrong here: this is the
    // main point of the page, not a footnote, so it should be there on
    // first paint.
    protected static bool $isLazy = false;

    protected static string $view = 'filament.flight-requests.itinerary-overview';

    public ?FlightRequest $record = null;

    protected int|string|array $columnSpan = 'full';

    public function getLegs()
    {
        return $this->record->legs()
            ->with(['originAirport', 'destinationAirport', 'services.supplier'])
            ->get();
    }

    public function canViewCosts(): bool
    {
        return Auth::user()->can('finance.view_costs');
    }

    public function canViewPrices(): bool
    {
        return Auth::user()->can('finance.view_prices');
    }

    public function needsReadinessWarning(): bool
    {
        return app(CheckFlightReadinessWarning::class)($this->record);
    }

    /** @return Collection<int, ReadinessIssue> */
    public function getReadinessIssues(): Collection
    {
        return app(CheckFlightReadiness::class)($this->record);
    }
}
