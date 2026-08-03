<?php

namespace App\Domain\Shared\Scopes;

use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Scopes every query on a tenant-owned model to the current company.
 *
 * There is no bypass short of app(CurrentCompany::class)->clear() or
 * Model::withoutGlobalScope(self::class) — both are deliberately loud
 * in code review, since a silent bypass here is a cross-tenant data leak.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $currentCompany = app(CurrentCompany::class);

        if ($currentCompany->isSet()) {
            $builder->where($model->qualifyColumn('company_id'), $currentCompany->id());
        }
    }
}
