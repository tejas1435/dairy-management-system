<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\DateFormat;
use App\Enums\Locale;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(PermissionCatalog::SETTINGS_MANAGE) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            /*
             * Currency, timezone, date format and locale are constrained to
             * values the application actually supports. An unsupported locale
             * would leave the interface untranslated, and an unknown timezone
             * would break every business date.
             */
            'currency' => ['required', 'string', Rule::in(['INR'])],
            'timezone' => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'date_format' => ['required', 'string', Rule::in(DateFormat::values())],
            'default_locale' => ['required', 'string', Rule::in(Locale::values())],
            'is_active' => ['nullable', 'boolean'],
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
            'name' => __('settings.business.fields.name'),
            'legal_name' => __('settings.business.fields.legal_name'),
            'mobile' => __('settings.business.fields.mobile'),
            'email' => __('settings.business.fields.email'),
            'address' => __('settings.business.fields.address'),
            'currency' => __('settings.business.fields.currency'),
            'timezone' => __('settings.business.fields.timezone'),
            'date_format' => __('settings.business.fields.date_format'),
            'default_locale' => __('settings.business.fields.default_locale'),
        ];
    }
}
