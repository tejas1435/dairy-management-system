<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminates the session of a user who has been deactivated.
 *
 * Checking is_active during login is not enough: an administrator who
 * deactivates someone who is already signed in must cut off that existing
 * session, not wait for it to expire. This runs on every authenticated request,
 * so the block takes effect on the deactivated user's very next page load.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(Response::HTTP_FORBIDDEN, __('auth.deactivated'));
            }

            return redirect()->route('login')->withErrors([
                'email' => __('auth.deactivated'),
            ]);
        }

        return $next($request);
    }
}
