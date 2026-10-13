<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Canonical display order for role lists (Roles & Permissions page,
     * the Users form role picker). Not creation order — just presentation.
     */
    public const ROLE_ORDER = ['Super Admin', 'Admin', 'Manager', 'Sales Person', 'Accountant', 'Factory Manager', 'HR', 'Employee'];

    /**
     * Base modules (README §3) and the actions available on each.
     * Permission names follow the "module.action" convention.
     */
    protected array $modules = [
        // Order-to-delivery cycle (docs/order-to-delivery.md):
        //   sales        — Sales Orders, Proforma Invoices, LC, Delivery
        //                  (approve = confirm SO / generate + issue PI / LC /
        //                  close, and see every Sales Person's orders;
        //                  deliver = post a delivery against a PI)
        //   requisitions — Inventory Requisitions raised under a PI
        //   purchases    — Purchase Orders + Goods Receipt (receive)
        //   production   — BOM setup + Production Orders under a PI
        'sales'        => ['view', 'create', 'edit', 'delete', 'approve', 'deliver'],
        'requisitions' => ['view', 'create', 'edit', 'delete', 'approve'],
        'purchases'    => ['view', 'create', 'edit', 'delete', 'receive'],
        'production'   => ['view', 'create', 'edit', 'delete', 'approve'],
        'inventory' => ['view', 'create', 'edit', 'delete', 'approve'],
        'parties'   => ['view', 'create', 'edit', 'delete'],
        'accounts'  => ['view', 'create', 'edit', 'delete', 'approve'],
        'projects'  => ['view', 'create', 'edit', 'delete'],
        'tasks'     => ['view', 'create', 'edit', 'delete'],
        'hrm'       => ['view', 'create', 'edit', 'delete', 'approve'],
        'reports'   => ['view'],
        'website'   => ['view', 'delete'],
        'settings'  => ['view', 'edit'],
        'users'     => ['view', 'create', 'edit', 'delete'],
        'roles'     => ['view', 'create', 'edit', 'delete'],
        'sites'     => ['view', 'create', 'edit', 'delete'],
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ($this->modules as $module => $actions) {
            foreach ($actions as $action) {
                Permission::findOrCreate("$module.$action");
            }
        }

        // Super Admin gets everything through Gate::before (see AppServiceProvider)
        Role::findOrCreate('Super Admin');

        // Admin = company owner, full access to every site and every module.
        // A user becomes a given Site's manager simply by holding the
        // "Manager" role and being assigned to that Site — no separate
        // "Site Manager" role is needed.
        Role::findOrCreate('Admin')
            ->syncPermissions(Permission::all());

        Role::findOrCreate('Manager')->syncPermissions([
            'sales.view', 'requisitions.view', 'purchases.view', 'production.view',
            'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.approve',
            'parties.view', 'parties.create', 'parties.edit',
            'projects.view', 'projects.create', 'projects.edit',
            'tasks.view', 'tasks.create', 'tasks.edit',
            // "Manager (own team only)" — view/approve their direct reports'
            // attendance & leave (Employee::visibleTo scopes this), but not
            // full HR record management (no hrm.create/edit/delete).
            'hrm.view', 'hrm.approve',
            'reports.view',
        ]);

        Role::findOrCreate('HR')->syncPermissions([
            'hrm.view', 'hrm.create', 'hrm.edit', 'hrm.approve',
            'projects.view', 'projects.create', 'projects.edit',
            'tasks.view', 'tasks.create', 'tasks.edit',
            'parties.view',
            'reports.view',
        ]);

        // Sales Person — enters and manages Sales Orders for their own
        // buyers only (SalesOrder::visibleTo scopes this; anyone holding
        // sales.approve sees everyone's). Confirming an order and turning
        // it into a Proforma Invoice is an Admin step (sales.approve).
        Role::findOrCreate('Sales Person')->syncPermissions([
            'sales.view', 'sales.create', 'sales.edit', 'sales.delete',
            'parties.view', 'parties.create',
            'inventory.view',
        ]);

        // Accounts — purchase cost entries (Purchase Orders), customer
        // collections against a PI, payments, and inventory valuation.
        Role::findOrCreate('Accountant')->syncPermissions([
            'accounts.view', 'accounts.create', 'accounts.edit', 'accounts.approve',
            'sales.view', 'requisitions.view',
            'purchases.view', 'purchases.create', 'purchases.edit',
            'inventory.view',
            'parties.view',
            // "Accountant (payroll approval)" — reviews and approves salary
            // runs before they post to Accounts, but doesn't manage HR records.
            'hrm.view', 'hrm.approve',
            'reports.view',
        ]);

        // Factory Manager / Production Responsible — BOM, Production Orders
        // under a PI (raw material consumption + status), raising
        // requisitions for what the factory needs, and receiving goods
        // into factory stock.
        Role::findOrCreate('Factory Manager')->syncPermissions([
            'production.view', 'production.create', 'production.edit', 'production.delete', 'production.approve',
            'requisitions.view', 'requisitions.create', 'requisitions.edit', 'requisitions.delete',
            'purchases.view', 'purchases.receive',
            'sales.view',
            'inventory.view', 'inventory.create',
        ]);

        // Auto-provisioned on Employee::enableLogin() — self-service, view-only
        // for now; full ESS scoping (own payslip/leave/attendance) is Level 2.
        Role::findOrCreate('Employee')->syncPermissions([
            'hrm.view',
        ]);
    }
}
