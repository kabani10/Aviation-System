<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\Pages;

use App\Domain\FlightRequests\Models\FlightRequest;
use App\Filament\Resources\FlightRequests\FlightRequestResource;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Concerns\HasFlightRequestBoardView;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Same table as ListFlightRequests, scoped to flights the logged-in user is
 * assigned to — its own page/route rather than a tab, per the sidebar
 * structure in FlightRequestResource::getNavigationItems().
 */
class ListMyAssignedFlightRequests extends ListRecords
{
    use HasFlightRequestBoardView;

    protected static string $resource = FlightRequestResource::class;

    protected static string $view = 'filament.flight-requests.pages.list-with-kanban';

    protected function getHeaderActions(): array
    {
        return [
            ...$this->getDisplayModeActions(),
            Actions\CreateAction::make(),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)->modifyQueryUsing(fn (Builder $query): Builder => $this->scopeToAssignedUser($query));
    }

    public function getTitle(): string
    {
        return 'My Assigned Requests';
    }

    protected function getKanbanQuery(): Builder
    {
        return $this->scopeToAssignedUser(FlightRequest::query());
    }

    private function scopeToAssignedUser(Builder $query): Builder
    {
        return $query->whereHas(
            'assignedUsers',
            fn (Builder $query): Builder => $query->whereKey(Auth::id()),
        );
    }
}
