<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\FinancialAccountType;
use App\Models\FinancialAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFinancialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('finance.account.manage') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var FinancialAccount $account */
        $account = $this->route('account');

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('financial_accounts', 'name')
                    ->where('business_id', $account->business_id)
                    ->ignore($account->getKey()),
            ],
            'type' => ['required', Rule::in(FinancialAccountType::values())],
            /*
             * Accepted but ignored by the controller once the account has
             * postings: restating the opening balance would restate every
             * derived balance since, silently.
             */
            'opening_balance' => ['required', 'numeric', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
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
