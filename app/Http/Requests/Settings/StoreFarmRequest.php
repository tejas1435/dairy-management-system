<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\BusinessContext;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFarmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(PermissionCatalog::SETTINGS_MANAGE) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $businessId = app(BusinessContext::class)->business()->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            /*
             * Codes are unique per business, matching the database constraint,
             * so the user gets a field-level message instead of a 500 from a
             * duplicate key.
             */
            'code' => [
                'required', 'string', 'max:20', 'alpha_dash',
                Rule::unique('farms', 'code')->where('business_id', $businessId),
            ],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => mb_strtoupper((string) $this->input('code')),
            'is_active' => $this->boolean('is_active'),
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
