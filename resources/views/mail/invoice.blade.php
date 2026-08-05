<p>Hello,</p>

<p>Please find your invoice below for the following flight:</p>

<ul>
    <li><strong>Invoice number:</strong> {{ $invoice->invoice_number }}</li>
    <li><strong>Flight:</strong> {{ $invoice->flightRequest->displayLabel() }}</li>
    <li><strong>Amount due:</strong> ${{ number_format($invoice->totalAmount(), 2) }}</li>
    @if ($invoice->due_date)
        <li><strong>Due:</strong> {{ $invoice->due_date->toDayDateTimeString() }}</li>
    @endif
</ul>

@if ($invoice->notes)
    <p>{{ $invoice->notes }}</p>
@endif

<p>Please let us know if you have any questions.</p>

<p>Thanks,<br>{{ config('app.name') }}</p>
