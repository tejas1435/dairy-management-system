<?php

declare(strict_types=1);

namespace App\Http\Controllers\Profile;

use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Http\Requests\Profile\UpdateProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Self-service profile.
 *
 * A user may change their own name, email and language. They may not change
 * their own roles or active status: the request only ever reads the validated
 * name, email and locale, so submitting extra fields achieves nothing.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user()->load('roles'),
            'locales' => Locale::cases(),
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'locale' => $validated['locale'],
        ]);

        /*
         * Changing the email invalidates any prior verification of it. Phase 1
         * does not require verified email to sign in, but the flag must not
         * keep asserting something that is no longer true.
         */
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $request->session()->put(SetLocale::SESSION_KEY, $user->locale->value);

        return redirect()->route('profile.edit')->with('status', __('profile.updated'));
    }
}
