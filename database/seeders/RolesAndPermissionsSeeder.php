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
            'flights.view',
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
            'services.view',
            'finance.view_costs',
        ],
        'Finance' => [
            'finance.view_costs', 'finance.view_prices', 'finance.manage',
            'quotations.view',
            'flights.view',
        ],
        'Management' => [
            'flights.view',
            'services.view',
            'suppliers.view',
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
