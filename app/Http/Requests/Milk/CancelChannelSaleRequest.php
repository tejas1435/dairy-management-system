<?php

declare(strict_types=1);

namespace App\Http\Requests\Milk;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the withdrawal of a recorded sale.
 *
 * A reason is required here and again in the action. Both, because the form message
 * is what the operator reads and the action's check is what holds when the sale is
 * cancelled by anything other than this form — a seeder, a console command, a future
 * workflow.
 */
class CancelChannelSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('milk.sale.cancel') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Long enough to be actionable by somebody reading it months later.
            'cancellation_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['cancellation_reason' => __('milk.fields.cancellation_reason')];
    }
}
