<p>Hello,</p>

<p>Please confirm you're booked to provide the following service at the agreed price:</p>

<ul>
    <li><strong>Service:</strong> {{ $inquiry->service->type->label() }}</li>
    <li><strong>Flight:</strong> {{ $inquiry->service->flightRequest->displayLabel() }}</li>
    @if ($inquiry->service->flightLeg)
        <li><strong>Route:</strong> {{ $inquiry->service->flightLeg->originAirport->icao_code }} &rarr; {{ $inquiry->service->flightLeg->destinationAirport->icao_code }}</li>
        @if ($inquiry->service->flightLeg->departure_at)
            <li><strong>Departure:</strong> {{ $inquiry->service->flightLeg->departure_at->toDayDateTimeString() }}</li>
        @endif
    @endif
    @if ($inquiry->cost !== null)
        <li><strong>Agreed price:</strong> ${{ number_format((float) $inquiry->cost, 2) }}</li>
    @endif
</ul>

@if ($note)
    <p>{{ $note }}</p>
@endif

<p>Please reply to confirm.</p>

<p>Thanks,<br>{{ config('app.name') }}</p>
