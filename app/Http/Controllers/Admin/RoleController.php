<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Services\AuditLogger;
use App\Support\PermissionCatalog;
use App\Support\RoleCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Roles and their permissions.
 *
 * Permission assignments are fully editable and take effect on the affected
 * users' next request, because Spatie's cache is flushed on write.
 *
 * Two things are protected:
 *
 *  - Seeded role names cannot be renamed or deleted. The seeder matches Super
 *    Admin by name to grant it the whole catalogue, so renaming it would leave
 *    that grant pointing at nothing, and deleting it could leave nobody able to
 *    administer the system.
 *  - Super Admin's permission set is not editable. It is defined as
 *    "everything", so an edit here would be undone by the next seed while
 *    leaving a window in which the top role cannot reach a feature.
 *
 * Neither of those is an authorisation shortcut: access still resolves purely
 * through permissions (docs/DECISIONS.md D19). These rules protect the role
 * *record*, not the permission check.
 */
class RoleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('admin.roles.index', [
            'roles' => Role::query()->withCount('permissions', 'users')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.roles.create', [
            'groups' => $this->permissionGroups(),
            'assigned' => [],
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated): void {
            $role = Role::create(['name' => $validated['name'], 'guard_name' => 'web']);
            $role->syncPermissions($validated['permissions'] ?? []);

            $this->audit->created($role, [
                'name' => $role->name,
                'permissions' => $validated['permissions'] ?? [],
            ], $role->name);
        });

        return redirect()->route('admin.roles.index')
            ->with('status', __('roles.created', ['name' => $validated['name']]));
    }

    public function edit(Role $role): View
    {
        return view('admin.roles.edit', [
            'role' => $role,
            'groups' => $this->permissionGroups(),
            'assigned' => $role->permissions->pluck('name')->all(),
            'isSystem' => RoleCatalog::isSystem($role->name),
            'permissionsLocked' => RoleCatalog::isAllPermissions($role->name),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $role): void {
            $nameBefore = $role->name;
            $permissionsBefore = $role->permissions->pluck('name')->sort()->values()->all();

            if (! RoleCatalog::isSystem($role->name)) {
                $role->update(['name' => $validated['name']]);
            }

            if (! RoleCatalog::isAllPermissions($role->name)) {
                $role->syncPermissions($validated['permissions'] ?? []);
            }

            if ($nameBefore !== $role->name) {
                $this->audit->updated($role, ['name' => $nameBefore], ['name' => $role->name], $role->name);
            }

            $permissionsAfter = $role->load('permissions')->permissions
                ->pluck('name')->sort()->values()->all();

            /*
             * Who can do what is the single most consequential setting in the
             * application, so a permission change is recorded with the full
             * before and after list rather than just "role updated".
             */
            if ($permissionsBefore !== $permissionsAfter) {
                $this->audit->custom(
                    AuditAction::PermissionsChanged,
                    $role,
                    ['permissions' => $permissionsBefore],
                    ['permissions' => $permissionsAfter],
                    $role->name,
                );
            }
        });

        return redirect()->route('admin.roles.index')
            ->with('status', __('roles.updated', ['name' => $role->name]));
    }

    public function destroy(Role $role): RedirectResponse
    {
        if (RoleCatalog::isSystem($role->name)) {
            throw ValidationException::withMessages([
                'role' => __('roles.errors.system_role_protected'),
            ]);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'role' => __('roles.errors.role_in_use'),
            ]);
        }

        $name = $role->name;

        DB::transaction(function () use ($role): void {
            $this->audit->custom(
                AuditAction::Deleted,
                $role,
                [
                    'name' => $role->name,
                    'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
                ],
                [],
                $role->name,
            );

            $role->delete();
        });

        return redirect()->route('admin.roles.index')
            ->with('status', __('roles.deleted', ['name' => $name]));
    }

    /**
     * Permissions grouped for the editor, including any an administrator added
     * outside the catalogue so they remain assignable.
     *
     * @return array<string, array<int, string>>
     */
    private function permissionGroups(): array
    {
        $groups = PermissionCatalog::groups();

        $extra = Permission::query()
            ->whereNotIn('name', PermissionCatalog::all())
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($extra !== []) {
            $groups['custom'] = $extra;
        }

        return $groups;
    }
}
