<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Locale;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(PermissionCatalog::USER_MANAGE) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var User $target */
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($target->getKey()),
            ],
            // Blank means "leave the existing password alone".
            'password' => ['nullable', 'string', 'confirmed', Password::defaults()],
            'locale' => ['required', 'string', Rule::in(Locale::values())],
            'is_active' => ['nullable', 'boolean'],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var User $target */
            $target = $this->route('user');

            /*
             * Guard against an administrator locking themselves out in a single
             * form submission.
             */
            if ($target->is($this->user()) && ! $this->boolean('is_active')) {
                $validator->errors()->add('is_active', __('users.errors.cannot_deactivate_self'));
            }
        });
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
