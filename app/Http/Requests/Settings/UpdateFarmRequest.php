<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\Farm;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFarmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(PermissionCatalog::SETTINGS_MANAGE) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var Farm $farm */
        $farm = $this->route('farm');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:20', 'alpha_dash',
                Rule::unique('farms', 'code')
                    ->where('business_id', $farm->business_id)
                    ->ignore($farm->getKey()),
            ],
            'address' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => mb_strtoupper((string) $this->input('code')),
        ]);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => __('settings.farm.fields.name'),
            'code' => __('settings.farm.fields.code'),
            'address' => __('settings.farm.fields.address'),
        ];
    }
}
