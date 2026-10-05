<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Sends a reset link.
     *
     * The response is always the same generic confirmation, whatever the broker
     * returns, so the form cannot be used to discover which addresses have
     * accounts. Deactivated users are additionally suppressed at the
     * notification level (User::sendPasswordResetNotification), and the reset
     * form refuses them even with a valid token.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        Password::sendResetLink($request->only('email'));

        return back()->with('status', __('passwords.sent_generic'));
    }
}
