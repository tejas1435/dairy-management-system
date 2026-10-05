<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Enums\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /** Any signed-in user may edit their own profile. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Note what is absent: roles and is_active. A user cannot promote themselves
     * or reactivate their own disabled account, because those fields are never
     * read from this request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->getKey()),
            ],
            'locale' => ['required', 'string', Rule::in(Locale::values())],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => __('profile.fields.name'),
            'email' => __('profile.fields.email'),
            'locale' => __('profile.fields.locale'),
        ];
    }
}
