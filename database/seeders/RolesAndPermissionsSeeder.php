<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Source of truth for what each of the six roles can do. Admin isn't listed
 * here with explicit permissions — it bypasses checks entirely via the
 * Gate::before hook in AppServiceProvider, since "can configure everything"
 * is Admin's whole point.
 *
 * Role → permission mapping is deliberately coarse (module.action, not one
 * permission per field) — split a module's permission further only when a
 * real requirement needs it, not speculatively.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    private const ROLE_PERMISSIONS = [
        'Sales' => [
            'customers.view', 'customers.manage',
            'quotations.view', 'quotations.manage',
            'communications.view', 'communications.manage',
            // flights.manage (not just .view) as of Phase 5: the original
            // spec has requests entered manually "by a sales or operations
            // employee" — Sales owns intake, Operations owns running the
            // flight afterward. This was a gap in the original Phase 1
            // seeder, written before Flight Request existed to check it against.
            'flights.view', 'flights.manage',
            // services.view + finance.view_prices as of Phase 6: the spec
            // says "Sales may see selling prices but not necessarily all
            // supplier costs" — deliberately no finance.view_costs here.
            // Selling price lives on the Service record, so view access to
            // services is what actually makes that visible; without it the
            // price grant has nothing to attach to.
            'services.view', 'finance.view_prices',
        ],
        'Operations' => [
            'flights.view', 'flights.manage',
            'services.view', 'services.manage',
            'suppliers.view',
            'documents.view', 'documents.manage',
            'communications.view', 'communications.manage',
        ],
        'Procurement' => [
            'suppliers.view', 'suppliers.manage',
            'flights.view',
            // services.manage (not just .view) as of Phase 8: Procurement is
            // who actually talks to suppliers — requesting quotes, recording
            // what came back — but until now only Operations/Sales could
            // manage a service at all, leaving Procurement able to see costs
            // (finance.view_costs, below) with no action that lets them
            // record one. Same class of gap as the Sales/Finance fixes in
            // Phase 5/6, just discovered a phase later.
            'services.view', 'services.manage',
            'finance.view_costs',
        ],
        'Finance' => [
            'finance.view_costs', 'finance.view_prices', 'finance.manage',
            'quotations.view',
            'flights.view',
            // services.view as of Phase 6: Finance's whole job per the spec
            // is "supplier costs, profitability, financial reports" — costs
            // live on the Service record (ServicesRelationManager), so
            // without this Finance could hold finance.view_costs and still
            // have no screen that shows a cost. Same class of gap as the
            // Sales fixes above.
            'services.view',
        ],
        'Management' => [
            'flights.view',
            'services.view',
            'suppliers.view',
            // quotations.view as of Phase 10: Management is view-only across
            // every other financial/operational module (flights, services,
            // suppliers, costs, prices) for oversight — quotations was the
            // one left out, simply because there was nothing to view until
            // this phase built the module. Same class of gap as the
            // Sales/Finance/Procurement fixes in earlier phases.
            'quotations.view',
            'finance.view_costs', 'finance.view_prices',
            'reports.view',
        ],
    ];

    public function run(): void
    {
        $permissionNames = collect(self::ROLE_PERMISSIONS)->flatten()->unique();

        $permissionNames->each(fn (string $name) => Permission::findOrCreate($name));

        foreach (self::ROLE_PERMISSIONS as $role => $permissions) {
            Role::findOrCreate($role)->syncPermissions($permissions);
        }

        Role::findOrCreate('Admin');
    }
}
