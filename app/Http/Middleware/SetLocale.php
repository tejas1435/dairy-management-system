<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request locale deterministically.
 *
 * Order of precedence:
 *   1. the authenticated user's persisted locale;
 *   2. a locale chosen in this session, which lets the login and password-reset
 *      screens be switched before anyone is signed in;
 *   3. the application default.
 *
 * Anything not in the supported set falls back to the default rather than
 * throwing, so a stale session value or a hand-edited database row cannot make
 * the application unreachable.
 */
class SetLocale
{
    public const SESSION_KEY = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request)->value);

        return $next($request);
    }

    private function resolve(Request $request): Locale
    {
        if ($user = $request->user()) {
            return $user->preferredLocale();
        }

        if ($request->hasSession() && $request->session()->has(self::SESSION_KEY)) {
            return Locale::tryFromOrDefault($request->session()->get(self::SESSION_KEY));
        }

        return Locale::tryFromOrDefault(config('app.locale'));
    }
}
