<?php

namespace App\Domain\Services\Models;

use App\Domain\Documents\Concerns\HasDocuments;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Domain\Shared\Enums\ServiceType;
use App\Domain\Suppliers\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line item on a flight — ground handling, fuel, a landing permit.
 * "Operational risks" from the original spec isn't a field here: that's
 * AI Risk Detection reading deadlines/confirmations/etc across services,
 * not something an operator types in manually. HasDocuments is on the
 * model (a service's own certificates/permits belong here, not on the
 * flight), but there's no nested RelationManager UI for it yet — Filament
 * doesn't nest a RelationManager inside another RelationManager, and
 * Service doesn't have its own top-level resource to hang one off of.
 */
#[Fillable([
    'type', 'status', 'responsible_user_id', 'supplier_id', 'cost', 'selling_price',
    'supplier_confirmed_at', 'deadline', 'notes',
])]
class Service extends Model
{
    use BelongsToCompany, HasDocuments, HasFactory;

    protected function casts(): array
    {
        return [
            'type' => ServiceType::class,
            'status' => ServiceStatus::class,
            'cost' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'supplier_confirmed_at' => 'datetime',
            'deadline' => 'datetime',
        ];
    }

    public function flightRequest(): BelongsTo
    {
        return $this->belongsTo(FlightRequest::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Computed, not stored — cost and selling_price already are the source
     * of truth, a third column would just be a copy that can drift.
     */
    public function profitMargin(): ?float
    {
        if ($this->cost === null || $this->selling_price === null) {
            return null;
        }

        return (float) $this->selling_price - (float) $this->cost;
    }

    public function isOverdue(): bool
    {
        return $this->deadline !== null
            && $this->deadline->isPast()
            && ! in_array($this->status, [ServiceStatus::Confirmed, ServiceStatus::Completed, ServiceStatus::Cancelled], strict: true);
    }

    public function displayLabel(): string
    {
        return "{$this->type->label()} — {$this->flightRequest->displayLabel()}";
    }
}
