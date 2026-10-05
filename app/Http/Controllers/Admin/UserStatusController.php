<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Activates and deactivates accounts from the user list.
 *
 * Deactivating takes effect on the target user's next request, not at their
 * next login: EnsureUserIsActive terminates the session they already hold.
 */
class UserStatusController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $active = (bool) $validated['is_active'];

        /*
         * An administrator deactivating their own account would be logged out
         * on the next request and might leave nobody able to sign in. Refusing
         * is cheaper than recovering.
         */
        if (! $active && $user->is($request->user())) {
            throw ValidationException::withMessages([
                'is_active' => __('users.errors.cannot_deactivate_self'),
            ]);
        }

        DB::transaction(function () use ($user, $active): void {
            $user->forceFill(['is_active' => $active])->save();

            // Cutting off someone's access is exactly the kind of change the
            // audit log exists for.
            $this->audit->statusChanged($user, $active, $user->name);
        });

        return back()->with('status', __(
            $active ? 'users.activated' : 'users.deactivated',
            ['name' => $user->name]
        ));
    }
}
