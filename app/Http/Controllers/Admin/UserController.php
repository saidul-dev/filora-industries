<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\RestrictsPermissionGrants;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserController extends Controller implements HasMiddleware
{
    use RestrictsPermissionGrants;

    /**
     * Display order for role lists — not creation order, just presentation.
     */
    protected const ROLE_ORDER = ['Super Admin', 'Admin', 'Manager', 'Sales Person', 'Accountant', 'Factory Manager', 'HR', 'Employee'];

    public static function middleware(): array
    {
        return [
            new Middleware('permission:users.view', only: ['index']),
            new Middleware('permission:users.create', only: ['create', 'store']),
            new Middleware('permission:users.edit', only: ['edit', 'update']),
            new Middleware('permission:users.delete', only: ['destroy']),
        ];
    }

    public function index()
    {
        $users = User::with('roles')->orderBy('name')->paginate(15);

        return view('admin.users.index', compact('users'));
    }

    public function create()
    {
        return view('admin.users.create', [
            'roles' => $this->assignableRoles(),
            'sites' => Site::orderBy('name')->get(),
            'permissions' => $this->permissionsByModule(),
            'rolePermissionsMap' => $this->rolePermissionsMap(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'roles' => ['array'],
            'roles.*' => [Rule::in($this->assignableRoles()->pluck('name'))],
            'sites' => ['array'],
            'sites.*' => ['exists:sites,id'],
            'default_site' => ['nullable', 'integer'],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in($this->grantablePermissionNames())],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        // New user has no prior roles/permissions, so nothing to preserve —
        // the submission is already validated against the acting user's own grant scope.
        $user->syncRoles($validated['roles'] ?? []);
        $user->syncPermissions($validated['permissions'] ?? []);
        $this->syncSites($user, $validated['sites'] ?? [], $validated['default_site'] ?? null);

        return redirect()->route('users.index')->with('success', "User \"{$user->name}\" created.");
    }

    public function edit(User $user)
    {
        $this->guardSuperAdminTarget($user);

        return view('admin.users.edit', [
            'user' => $user,
            'roles' => $this->assignableRoles(),
            'sites' => Site::orderBy('name')->get(),
            'permissions' => $this->permissionsByModule(),
            'rolePermissionsMap' => $this->rolePermissionsMap(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $this->guardSuperAdminTarget($user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'roles' => ['array'],
            'roles.*' => [Rule::in($this->assignableRoles()->pluck('name'))],
            'sites' => ['array'],
            'sites.*' => ['exists:sites,id'],
            'default_site' => ['nullable', 'integer'],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in($this->grantablePermissionNames())],
        ]);

        // A restricted editor's form never even shows roles/permissions outside
        // their own grant scope, so preserve whatever the user already had there
        // instead of letting an invisible-to-them field get silently wiped.
        $finalRoles = $this->mergeRestrictedSelection(
            $user->roles->pluck('name'), $validated['roles'] ?? [], $this->grantableRoles()->pluck('name')
        );
        $finalPermissions = $this->mergeRestrictedSelection(
            $user->getDirectPermissions()->pluck('name'), $validated['permissions'] ?? [], $this->grantablePermissionNames()
        );

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();
        $user->syncRoles($finalRoles);
        $user->syncPermissions($finalPermissions);
        $this->syncSites($user, $validated['sites'] ?? [], $validated['default_site'] ?? null);

        return redirect()->route('users.index')->with('success', "User \"{$user->name}\" updated.");
    }

    public function destroy(User $user)
    {
        $this->guardSuperAdminTarget($user);

        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', "User \"{$user->name}\" deleted.");
    }

    /**
     * Roles the acting user may hand out — Super Admin is reserved for
     * Super Admins, and beyond that nobody can grant a role that carries
     * permissions they don't themselves hold (see RestrictsPermissionGrants).
     */
    protected function assignableRoles()
    {
        return $this->grantableRoles()
            ->sortBy(function ($role) {
                $index = array_search($role->name, self::ROLE_ORDER);

                return $index === false ? 999 : $index;
            })
            ->values();
    }

    /**
     * "sales.view" => grouped under "sales", for the Direct Permissions matrix.
     * Only permissions the acting user themselves holds are offered.
     */
    protected function permissionsByModule()
    {
        return Permission::whereIn('name', $this->grantablePermissionNames())->orderBy('name')->get()
            ->groupBy(fn ($permission) => explode('.', $permission->name)[0]);
    }

    /**
     * Role name => permission names it grants, so the Direct Permissions
     * matrix can show "already granted via role" as the Roles checkboxes change.
     */
    protected function rolePermissionsMap()
    {
        return Role::with('permissions')->get()
            ->mapWithKeys(fn ($role) => [$role->name => $role->permissions->pluck('name')]);
    }

    protected function guardSuperAdminTarget(User $user): void
    {
        abort_if(
            $user->hasRole('Super Admin') && ! auth()->user()->hasRole('Super Admin'),
            403,
            'Only a Super Admin can manage a Super Admin account.'
        );
    }

    /**
     * Sync a user's Site assignments. If exactly one site is assigned it is
     * always the default; otherwise the submitted default_site wins (falling
     * back to the first assigned site so there's always one selected).
     */
    protected function syncSites(User $user, array $siteIds, ?int $defaultSiteId): void
    {
        $siteIds = array_map('intval', $siteIds);

        if (count($siteIds) === 1) {
            $defaultSiteId = $siteIds[0];
        } elseif (! in_array($defaultSiteId, $siteIds)) {
            $defaultSiteId = $siteIds[0] ?? null;
        }

        $user->sites()->sync(collect($siteIds)->mapWithKeys(fn ($id) => [
            $id => ['is_default' => $id === $defaultSiteId],
        ]));

        if (! in_array($user->current_site_id, $siteIds)) {
            $user->update(['current_site_id' => $defaultSiteId]);
        }
    }
}
