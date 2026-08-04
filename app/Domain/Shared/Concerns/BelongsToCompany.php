<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\Shared\Scopes\CompanyScope;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every model that belongs to exactly one tenant company.
 * Pairs with CompanyScope for read isolation and auto-fills company_id
 * on create so callers can't forget it.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model) {
            // A default, not an override: if the caller already set
            // company_id explicitly (via ->for($company), or directly, as
            // RegisterCompany and InviteEmployee do), that wins. Without
            // this check, a CurrentCompany left set from earlier in the
            // same request/console-command/test would silently reassign
            // the record to the wrong tenant.
            if ($model->company_id !== null) {
                return;
            }

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
