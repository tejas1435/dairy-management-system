<?php

declare(strict_types=1);

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class PasswordController extends Controller
{
    /**
     * Changes the signed-in user's password.
     *
     * The current password is required, so someone who finds an unattended
     * browser cannot lock the owner out of their own account.
     *
     * Afterwards this user's other sessions are deleted. If the old password had
     * leaked, changing it should end the sessions it created. This works on the
     * session rows directly because the application uses the database session
     * driver; Auth::logoutOtherDevices would require the AuthenticateSession
     * middleware and would silently do nothing without it.
     */
    public function update(UpdatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => $request->validated()['password'],
        ])->save();

        $request->session()->regenerate();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        return redirect()->route('profile.edit')->with('status', __('profile.password_updated'));
    }
}
