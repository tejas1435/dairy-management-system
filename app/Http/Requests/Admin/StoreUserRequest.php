<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Locale;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(PermissionCatalog::USER_MANAGE) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'locale' => ['required', 'string', Rule::in(Locale::values())],
            'is_active' => ['nullable', 'boolean'],
            'roles' => ['array'],
            // Roles must exist. Permissions are granted through roles, never
            // directly to a user, so there is no permissions field here.
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => __('users.fields.name'),
            'email' => __('users.fields.email'),
            'password' => __('users.fields.password'),
            'locale' => __('users.fields.locale'),
            'roles' => __('users.fields.roles'),
        ];
    }
}
