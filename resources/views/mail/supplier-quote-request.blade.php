<p>Hello,</p>

<p>We would like a quote for the following service:</p>

<ul>
    <li><strong>Service:</strong> {{ $service->type->label() }}</li>
    <li><strong>Flight:</strong> {{ $service->flightRequest->displayLabel() }}</li>
    <li><strong>Route:</strong> {{ $service->flightLeg->originAirport->icao_code }} &rarr; {{ $service->flightLeg->destinationAirport->icao_code }}</li>
    <li><strong>Departure:</strong> {{ $service->flightLeg->departure_at->toDayDateTimeString() }}</li>
    @if ($service->deadline)
        <li><strong>Needed by:</strong> {{ $service->deadline->toDayDateTimeString() }}</li>
    @endif
</ul>

@if ($message)
    <p>{{ $message }}</p>
@endif

<p>Please reply with your quote at your earliest convenience.</p>

<p>Thanks,<br>{{ config('app.name') }}</p>
