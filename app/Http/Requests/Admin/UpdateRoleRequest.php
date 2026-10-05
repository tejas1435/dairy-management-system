<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\PermissionCatalog;
use App\Support\RoleCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(PermissionCatalog::ROLE_MANAGE) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var Role $role */
        $role = $this->route('role');

        return [
            /*
             * A seeded role's name is a fixed identifier, so the field is
             * required but must match what is already stored. The controller
             * ignores it for system roles regardless; validating it here means
             * the form cannot appear to succeed at a rename it did not perform.
             */
            'name' => RoleCatalog::isSystem($role->name)
                ? ['required', 'string', Rule::in([$role->name])]
                : [
                    'required', 'string', 'max:255',
                    Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role->getKey()),
                ],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.in' => __('roles.errors.system_role_protected'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => __('roles.fields.name'),
            'permissions' => __('roles.fields.permissions'),
        ];
    }
}
