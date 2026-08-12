<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\Pages;

use App\Filament\Resources\FlightRequests\FlightRequestResource;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Concerns\HasFlightExecutionActions;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Concerns\HasFlightRequestReviewActions;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Concerns\HasFlightStatusUpdateAction;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Widgets\FlightItineraryOverview;
use Filament\Resources\Pages\EditRecord;

/** No delete action — set status to Cancelled instead, same "no hard delete" convention as everywhere else. */
class EditFlightRequest extends EditRecord
{
    use HasFlightExecutionActions;
    use HasFlightRequestReviewActions;
    use HasFlightStatusUpdateAction;

    protected static string $resource = FlightRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...parent::getHeaderActions(),
            ...$this->flightRequestReviewHeaderActions(),
            ...$this->flightExecutionHeaderActions(),
            ...$this->flightStatusUpdateHeaderActions(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            FlightItineraryOverview::class,
        ];
    }
}
