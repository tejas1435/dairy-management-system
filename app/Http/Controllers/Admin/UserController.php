<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAction;
use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Administration of user accounts.
 *
 * Every action here is gated on the user.manage permission by the route group;
 * the sidebar hiding the menu item is presentation, not protection.
 *
 * Accounts are deactivated rather than deleted. A user who has entered milk,
 * recorded a payment or approved an adjustment is referenced by that history,
 * and destroying the row would leave those records attributed to nobody.
 */
class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,inactive'],
            'role' => ['nullable', 'string', 'max:255'],
        ]);

        $users = User::query()
            ->with('roles')
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($filters['role'] ?? null, fn ($q, string $role) => $q->whereHas(
                'roles', fn ($q) => $q->where('name', $role)
            ))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        return view('admin.users.create', [
            'roles' => Role::query()->orderBy('name')->get(),
            'locales' => Locale::cases(),
        ]);
    }

    public function store(StoreUserRequest $request, BusinessContext $context): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $context): void {
            $user = User::create([
                'business_id' => $context->business()->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'locale' => $validated['locale'],
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $user->syncRoles($validated['roles'] ?? []);

            /*
             * Audited inside the transaction, so a failed role assignment
             * leaves neither the user nor a record claiming one was created.
             * The password is never passed here; AuditLogger would redact it
             * anyway, but the safest value is the one that is never sent.
             */
            $this->audit->created($user, [
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale->value,
                'is_active' => $user->is_active,
            ], $user->name);

            $this->audit->custom(
                AuditAction::RolesChanged,
                $user,
                ['roles' => []],
                ['roles' => $validated['roles'] ?? []],
                $user->name,
            );
        });

        return redirect()->route('admin.users.index')
            ->with('status', __('users.created', ['name' => $validated['name']]));
    }

    public function edit(User $user): View
    {
        return view('admin.users.edit', [
            'user' => $user->load('roles'),
            'roles' => Role::query()->orderBy('name')->get(),
            'locales' => Locale::cases(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $user): void {
            $tracked = ['name', 'email', 'locale', 'is_active'];
            $before = $user->only($tracked);
            $rolesBefore = $user->roles->pluck('name')->sort()->values()->all();

            $user->fill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'locale' => $validated['locale'],
                'is_active' => $validated['is_active'] ?? false,
            ]);

            $passwordChanged = filled($validated['password'] ?? null);

            if ($passwordChanged) {
                $user->password = $validated['password'];
            }

            $user->save();

            $user->syncRoles($validated['roles'] ?? []);

            $this->audit->updated($user, $before, $user->only($tracked), $user->name);

            /*
             * Recorded as a fact, never with the value.
             *
             * The key is deliberately not "password": the redactor strips any
             * key containing that word, which would replace the marker with
             * "[redacted]" and lose the very thing this entry exists to say.
             * A neutral key carries the fact past the redactor while the
             * password itself is still never passed in.
             */
            if ($passwordChanged) {
                $this->audit->custom(
                    AuditAction::Updated,
                    $user,
                    [],
                    ['security_event' => 'password_changed'],
                    $user->name,
                );
            }

            $rolesAfter = $user->load('roles')->roles->pluck('name')->sort()->values()->all();

            if ($rolesBefore !== $rolesAfter) {
                $this->audit->custom(
                    AuditAction::RolesChanged,
                    $user,
                    ['roles' => $rolesBefore],
                    ['roles' => $rolesAfter],
                    $user->name,
                );
            }
        });

        return redirect()->route('admin.users.index')
            ->with('status', __('users.updated', ['name' => $user->name]));
    }
}
