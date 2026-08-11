<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\Concerns;

use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * A list/kanban toggle shared by ListFlightRequests and
 * ListMyAssignedFlightRequests — the only difference between the two pages
 * is which records they're scoped to (see each page's table()/
 * getKanbanQuery()), so the toggle, the board rendering, and the
 * drag-to-change-status action live here once. Both using classes must
 * declare their own `protected static string $view =
 * 'filament.flight-requests.pages.list-with-kanban';` directly — a trait
 * can't declare it here, since PHP treats a trait's property as
 * conflicting with ListRecords' own $view rather than overriding it. That
 * shared Blade template reads $displayMode to decide whether to render
 * {{ $this->table }} or the kanban board partial.
 */
trait HasFlightRequestBoardView
{
    #[Url(as: 'view')]
    public string $displayMode = 'list';

    abstract protected function getKanbanQuery(): Builder;

    /** @return array<int, Action> */
    protected function getDisplayModeActions(): array
    {
        return [
            Action::make('listView')
                ->label('List')
                ->icon('heroicon-o-bars-3')
                ->color(fn (): string => $this->displayMode === 'list' ? 'primary' : 'gray')
                ->action(fn () => $this->displayMode = 'list'),

            Action::make('kanbanView')
                ->label('Board')
                ->icon('heroicon-o-view-columns')
                ->color(fn (): string => $this->displayMode === 'kanban' ? 'primary' : 'gray')
                ->action(fn () => $this->displayMode = 'kanban'),
        ];
    }

    /** @return array<string, Collection<int, FlightRequest>> every FlightStatus value, in enum order, even when empty */
    public function getKanbanColumns(): array
    {
        $recordsByStatus = $this->getKanbanQuery()
            ->with(['customer', 'assignedUsers', 'legs.originAirport', 'legs.destinationAirport'])
            ->get()
            ->groupBy(fn (FlightRequest $flightRequest): string => $flightRequest->status->value);

        return collect(FlightStatus::cases())
            ->mapWithKeys(fn (FlightStatus $status): array => [
                $status->value => $recordsByStatus->get($status->value) ?? new Collection,
            ])
            ->all();
    }

    /**
     * Dragging a card to a new column calls this — scoped through the same
     * getKanbanQuery() the board was rendered from, so a user can't move a
     * flight request that isn't even visible to them (company/assignment
     * scoping), on top of the flights.manage authorization check below.
     */
    public function moveFlightRequest(int $flightRequestId, string $status): void
    {
        $newStatus = FlightStatus::tryFrom($status);

        abort_if($newStatus === null, 422);

        $flightRequest = $this->getKanbanQuery()->findOrFail($flightRequestId);

        abort_unless(Auth::user()->can('update', $flightRequest), 403);

        $flightRequest->update(['status' => $newStatus]);
    }
}
