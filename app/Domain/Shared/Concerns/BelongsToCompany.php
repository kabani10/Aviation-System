<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\Shared\Scopes\CompanyScope;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every model that belongs to exactly one tenant company.
 * Pairs with CompanyScope for read isolation and auto-fills company_id
 * on create so callers can't forget it (or spoof a different tenant's id).
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model) {
            $currentCompany = app(CurrentCompany::class);

            if ($currentCompany->isSet()) {
                $model->company_id = $currentCompany->id();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
