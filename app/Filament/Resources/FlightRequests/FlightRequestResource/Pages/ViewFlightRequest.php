<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\Pages;

use App\Filament\Resources\FlightRequests\FlightRequestResource;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Concerns\HasFlightExecutionActions;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Concerns\HasFlightRequestReviewActions;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Concerns\HasFlightStatusUpdateAction;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Widgets\FlightItineraryOverview;
use Filament\Resources\Pages\ViewRecord;

/**
 * Without this, a view-only role (flights.view but not flights.manage —
 * Procurement, Finance, Management) has no page to open at all: List is the
 * only other route, and Edit requires update rights. That also means no
 * access to the Services/Documents/Communications relation managers, which
 * gate on their own permissions independently of this page.
 */
class ViewFlightRequest extends ViewRecord
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
