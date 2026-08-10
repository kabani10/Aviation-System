<p>Hello,</p>

<p>Please find your quotation below for the following flight:</p>

<ul>
    <li><strong>Flight:</strong> {{ $quotation->flightRequest->displayLabel() }}</li>
    @foreach ($quotation->flightRequest->legs as $leg)
        <li>
            <strong>Leg {{ $leg->sequence }}:</strong>
            {{ $leg->originAirport->icao_code }} &rarr; {{ $leg->destinationAirport->icao_code }},
            departing {{ $leg->departure_at->toDayDateTimeString() }}
        </li>
    @endforeach
</ul>

<table cellpadding="6" cellspacing="0" border="1" style="border-collapse: collapse; width: 100%;">
    <thead>
        <tr>
            <th align="left">Service</th>
            <th align="right">Price</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($quotation->lineItems as $lineItem)
            <tr>
                <td>{{ $lineItem->description }}</td>
                <td align="right">${{ number_format((float) $lineItem->selling_price, 2) }}</td>
            </tr>
        @endforeach
        <tr>
            <td><strong>Total</strong></td>
            <td align="right"><strong>${{ number_format($quotation->totalSellingPrice(), 2) }}</strong></td>
        </tr>
    </tbody>
</table>

@if ($quotation->valid_until)
    <p>This quotation is valid until {{ $quotation->valid_until->toDayDateTimeString() }}.</p>
@endif

@if ($quotation->notes)
    <p>{{ $quotation->notes }}</p>
@endif

<p>Please let us know if you would like to proceed.</p>

<p>Thanks,<br>{{ config('app.name') }}</p>
