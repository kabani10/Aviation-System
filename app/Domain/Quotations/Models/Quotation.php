<?php

namespace App\Domain\Quotations\Models;

use App\Domain\Communications\Concerns\HasCommunications;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A formal offer sent to the customer for one flight — the spec's
 * "Quotation Sent to Customer" step. Generated from the flight's currently
 * priced Services (CreateQuotationFromServices), but deliberately not a
 * live view of them: lineItems are a snapshot taken at generation time, so
 * a quotation a customer already accepted or rejected doesn't silently
 * change if someone edits a Service's selling_price afterward. Multiple
 * quotations per flight are allowed (a rejected quote gets superseded by a
 * new one, not edited in place) — history stays intact.
 */
#[Fillable(['flight_request_id', 'status', 'created_by', 'valid_until', 'sent_at', 'responded_at', 'notes'])]
class Quotation extends Model
{
    use BelongsToCompany, HasCommunications, HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'valid_until' => 'datetime',
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function flightRequest(): BelongsTo
    {
        return $this->belongsTo(FlightRequest::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(QuotationLineItem::class);
    }

    /** Null (not zero) when no line item has a cost recorded — nothing to sum, not "$0 of cost". */
    public function totalCost(): ?float
    {
        $costs = $this->lineItems->pluck('cost')->filter(fn (?string $cost): bool => $cost !== null);

        return $costs->isEmpty() ? null : (float) $costs->sum();
    }

    public function totalSellingPrice(): float
    {
        return (float) $this->lineItems->sum('selling_price');
    }

    /** Same "computed on read, never stored" reasoning as Service::profitMargin(). */
    public function profitMargin(): ?float
    {
        $totalCost = $this->totalCost();

        return $totalCost === null ? null : $this->totalSellingPrice() - $totalCost;
    }

    /** Sent but past its validity with no response yet — see CheckOperationalRisks. */
    public function isExpired(): bool
    {
        return $this->status === QuotationStatus::Sent
            && $this->valid_until !== null
            && $this->valid_until->isPast();
    }

    public function displayLabel(): string
    {
        return "Quotation for {$this->flightRequest->displayLabel()} ({$this->status->label()})";
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'valid_until', 'sent_at', 'responded_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
