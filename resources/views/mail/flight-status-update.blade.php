<p>Hello,</p>

<p>Here is the current status of your flight:</p>

<ul>
    <li><strong>Flight:</strong> {{ $flightRequest->displayLabel() }}</li>
    <li><strong>Overall status:</strong> {{ $flightRequest->status->label() }}</li>
</ul>

@foreach ($flightRequest->legs as $leg)
    <p style="margin-bottom: 4px;">
        <strong>Leg {{ $leg->sequence }}:</strong>
        {{ $leg->originAirport->icao_code }} &rarr; {{ $leg->destinationAirport->icao_code }}
        @if ($leg->departure_at)
            &middot; departing {{ $leg->departure_at->toDayDateTimeString() }}
        @endif
    </p>

    @if ($leg->services->isEmpty())
        <p style="margin-top: 0; color: #666;">No services on record for this leg yet.</p>
    @else
        <table cellpadding="6" cellspacing="0" border="1" style="border-collapse: collapse; width: 100%; margin-bottom: 16px;">
            <thead>
                <tr>
                    <th align="left">Service</th>
                    <th align="left">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($leg->services as $service)
                    <tr>
                        <td>{{ $service->type->label() }}</td>
                        <td>{{ $service->status->label() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endforeach

@if ($note)
    <p>{{ $note }}</p>
@endif

<p>Please let us know if you have any questions.</p>

<p>Thanks,<br>{{ config('app.name') }}</p>
