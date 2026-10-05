<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartnerContributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('partner.contribution.create') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'contribution_date' => ['required', 'date'],
            // Zero-value financial records are not valid (MASTER_SPEC 63).
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'financial_account_id' => ['required', Rule::exists('financial_accounts', 'id')],
            'payment_method_id' => ['nullable', Rule::exists('payment_methods', 'id')],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'contribution_date' => __('partners.fields.contribution_date'),
            'amount' => __('partners.fields.amount'),
            'financial_account_id' => __('partners.fields.account'),
            'payment_method_id' => __('partners.fields.payment_method'),
        ];
    }
}
