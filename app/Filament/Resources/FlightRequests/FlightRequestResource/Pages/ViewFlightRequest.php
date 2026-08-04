<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\Pages;

use App\Filament\Resources\FlightRequests\FlightRequestResource;
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
    protected static string $resource = FlightRequestResource::class;
}
