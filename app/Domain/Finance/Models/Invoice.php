<?php

namespace App\Domain\Finance\Models;

use App\Domain\Communications\Concerns\HasCommunications;
use App\Domain\Finance\Enums\InvoiceStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The bill sent to the customer once a flight is Completed — the spec's
 * final financial step, generated from whichever Quotation the customer
 * actually accepted (CreateInvoiceFromQuotation). Deliberately has no
 * line items or stored total of its own: the accepted Quotation's
 * lineItems are already an immutable snapshot (see Quotation's docblock),
 * so totalAmount()/profitMargin() just delegate to it rather than copying
 * the same numbers into a second frozen table.
 */
#[Fillable(['flight_request_id', 'quotation_id', 'invoice_number', 'status', 'created_by', 'due_date', 'sent_at', 'paid_at', 'notes'])]
class Invoice extends Model
{
    use BelongsToCompany, HasCommunications, HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'due_date' => 'datetime',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function flightRequest(): BelongsTo
    {
        return $this->belongsTo(FlightRequest::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function totalAmount(): float
    {
        return $this->quotation->totalSellingPrice();
    }

    public function profitMargin(): ?float
    {
        return $this->quotation->profitMargin();
    }

    /** Sent but past its due date with no payment yet — see CheckOperationalRisks. */
    public function isOverdue(): bool
    {
        return $this->status === InvoiceStatus::Sent
            && $this->due_date !== null
            && $this->due_date->isPast();
    }

    public function displayLabel(): string
    {
        return "Invoice {$this->invoice_number} for {$this->flightRequest->displayLabel()}";
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'due_date', 'sent_at', 'paid_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
