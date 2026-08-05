<?php

namespace App\Domain\Quotations\Models;

use App\Domain\Services\Models\Service;
use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line on a Quotation — a snapshot of a Service's type/cost/selling_price at generation time. See Quotation's docblock for why this isn't a live join. */
#[Fillable(['service_id', 'description', 'cost', 'selling_price'])]
class QuotationLineItem extends Model
{
    use BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'selling_price' => 'decimal:2',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** The live Service this line item was snapshotted from — may have since changed price, or been cancelled; the snapshot above is the source of truth for this quotation. */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
