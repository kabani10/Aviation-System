<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\Pages;

use App\Filament\Resources\FlightRequests\FlightRequestResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * The form collects one leg's worth of route/timing inline (see
 * FlightRequestResource::form) even though FlightRequest itself no longer
 * has those columns — this is where that gets split: the flight record
 * gets everything else, and a sequence-1 FlightLeg gets the route.
 */
class CreateFlightRequest extends CreateRecord
{
    protected static string $resource = FlightRequestResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $legData = [
            'origin_airport_id' => $data['origin_airport_id'],
            'destination_airport_id' => $data['destination_airport_id'],
            'departure_at' => $data['departure_at'],
            'arrival_at' => $data['arrival_at'],
        ];

        unset($data['origin_airport_id'], $data['destination_airport_id'], $data['departure_at'], $data['arrival_at']);

        $flightRequest = static::getModel()::create($data);

        $flightRequest->legs()->create([...$legData, 'sequence' => 1]);

        return $flightRequest;
    }
}
