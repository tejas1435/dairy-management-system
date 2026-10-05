<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Locale;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Switches the interface language.
 *
 * Works for guests as well as signed-in users: the login and password-reset
 * screens need to be switchable by someone who cannot read English and has not
 * signed in yet. For a guest the choice lives in the session; for an
 * authenticated user it is persisted on the user record so it follows them to
 * any device.
 */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(Locale::values())],
        ]);

        $locale = Locale::from($validated['locale']);

        $request->session()->put(SetLocale::SESSION_KEY, $locale->value);

        if ($user = $request->user()) {
            $user->forceFill(['locale' => $locale->value])->save();
        }

        return back()->with('status', __('profile.language_updated'));
    }
}
