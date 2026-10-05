<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\FinancialAccountType;
use App\Services\BusinessContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('finance.account.manage') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $businessId = app(BusinessContext::class)->business()->id;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('financial_accounts', 'name')->where('business_id', $businessId),
            ],
            'type' => ['required', Rule::in(FinancialAccountType::values())],
            'opening_balance' => ['required', 'numeric', 'decimal:0,2', 'min:-9999999999.99', 'max:9999999999.99'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active', true),
            'opening_balance' => $this->input('opening_balance', '0'),
        ]);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => __('finance.accounts.fields.name'),
            'type' => __('finance.accounts.fields.type'),
            'opening_balance' => __('finance.accounts.fields.opening_balance'),
        ];
    }
}
