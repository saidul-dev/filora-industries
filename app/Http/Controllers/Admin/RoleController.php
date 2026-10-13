<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\RestrictsPermissionGrants;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller implements HasMiddleware
{
    use RestrictsPermissionGrants;

    /**
     * Display order for role lists — not creation order, just presentation.
     */
    protected const ROLE_ORDER = ['Super Admin', 'Admin', 'Manager', 'Sales Person', 'Accountant', 'Factory Manager', 'HR', 'Employee'];

    public static function middleware(): array
    {
        return [
            new Middleware('permission:roles.view', only: ['index']),
            new Middleware('permission:roles.create', only: ['create', 'store']),
            new Middleware('permission:roles.edit', only: ['edit', 'update']),
            new Middleware('permission:roles.delete', only: ['destroy']),
        ];
    }

    public function index()
    {
        $roles = Role::with('permissions')->withCount('users')->get()
            ->sortBy(function ($role) {
                $index = array_search($role->name, self::ROLE_ORDER);

                return $index === false ? 999 : $index;
            })
            ->values();

        $permissions = $this->permissionsByModule();

        return view('admin.roles.index', compact('roles', 'permissions'));
    }

    public function create()
    {
        return view('admin.roles.create', ['permissions' => $this->assignablePermissionsByModule()]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:125', 'unique:roles,name'],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in($this->grantablePermissionNames())],
        ]);

        // New role has no prior permissions, so nothing to preserve — the
        // submission is already validated against the acting user's own grant scope.
        $role = Role::create(['name' => $validated['name']]);
        $role->syncPermissions($validated['permissions'] ?? []);

        return redirect()->route('roles.index')->with('success', "Role \"{$role->name}\" created.");
    }

    public function edit(Role $role)
    {
        $this->guardSuperAdminRole($role);

        return view('admin.roles.edit', [
            'role' => $role,
            'permissions' => $this->assignablePermissionsByModule(),
        ]);
    }

    public function update(Request $request, Role $role)
    {
        $this->guardSuperAdminRole($role);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:125', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in($this->grantablePermissionNames())],
        ]);

        // A restricted editor's form never shows permissions outside their own
        // grant scope, so preserve whatever the role already had there instead
        // of letting an invisible-to-them permission get silently wiped.
        $finalPermissions = $this->mergeRestrictedSelection(
            $role->permissions->pluck('name'), $validated['permissions'] ?? [], $this->grantablePermissionNames()
        );

        $role->update(['name' => $validated['name']]);
        $role->syncPermissions($finalPermissions);

        return redirect()->route('roles.index')->with('success', "Role \"{$role->name}\" updated.");
    }

    public function destroy(Role $role)
    {
        $this->guardSuperAdminRole($role);

        if ($role->users()->exists()) {
            return back()->with('error', "Role \"{$role->name}\" still has users assigned — reassign them first.");
        }

        $role->delete();

        return redirect()->route('roles.index')->with('success', "Role \"{$role->name}\" deleted.");
    }

    /**
     * "sales.view" => grouped under "sales". Every permission — used for the
     * read-only index matrix, which is informational, not a grant action.
     */
    protected function permissionsByModule()
    {
        return Permission::orderBy('name')->get()
            ->groupBy(fn ($permission) => explode('.', $permission->name)[0]);
    }

    /**
     * Same grouping, but only permissions the acting user themselves holds —
     * used for the create/edit forms, where checking a box is a grant action.
     */
    protected function assignablePermissionsByModule()
    {
        return Permission::whereIn('name', $this->grantablePermissionNames())->orderBy('name')->get()
            ->groupBy(fn ($permission) => explode('.', $permission->name)[0]);
    }

    protected function guardSuperAdminRole(Role $role): void
    {
        abort_if(
            $role->name === 'Super Admin',
            403,
            'The Super Admin role is protected and cannot be modified.'
        );
    }
}
