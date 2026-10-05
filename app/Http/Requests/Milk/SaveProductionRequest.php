<?php

declare(strict_types=1);

namespace App\Http\Requests\Milk;

use App\Enums\Shift;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the production matrix: cow and buffalo, morning and evening, for one
 * date.
 *
 * `decimal:0,3` is the rule that matters. Without it a browser or a script could
 * post 4.4567 litres, MySQL would round it to 4.457 on the way in, and the
 * reconciliation arithmetic would then be exactly right about a figure nobody
 * entered. Rejecting the input is better than silently storing a different number.
 *
 * Quantities are `gte:0` rather than `gt:0`: a shift that genuinely produced no
 * buffalo milk is a real thing to record, and requiring both types to be positive
 * would force people to invent a number.
 */
class SaveProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('milk.production.create') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $quantity = [
            'nullable',
            'numeric',
            'gte:0',
            // Three decimals, matching DECIMAL(10,3); max fits the column.
            'decimal:0,3',
            'max:9999999.999',
        ];

        return [
            'production_date' => ['required', 'date'],

            // min:1 counts array members, so a submission whose shift keys were
            // all discarded below fails here rather than saving nothing quietly.
            'shifts' => ['required', 'array', 'min:1'],
            'shifts.*.cow' => $quantity,
            'shifts.*.buffalo' => $quantity,
            'shifts.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Discards submitted keys that are not real shifts.
     *
     * A wildcard rule cannot check the keys themselves, and an unrecognised shift
     * should not reach the action as data it silently skips.
     */
    protected function prepareForValidation(): void
    {
        $shifts = $this->input('shifts');

        if (! is_array($shifts)) {
            return;
        }

        $this->merge([
            'shifts' => array_intersect_key($shifts, array_flip(Shift::values())),
        ]);
    }

    /**
     * The shift rows, normalised so the action receives decimal strings.
     *
     * An empty input is a recorded zero, not a skipped field: the row exists
     * because somebody saved this shift.
     *
     * @return array<string, array{cow: string, buffalo: string, notes: string|null}>
     */
    public function shiftRows(): array
    {
        $rows = [];

        foreach ((array) $this->validated('shifts') as $shift => $row) {
            $rows[(string) $shift] = [
                'cow' => (string) ($row['cow'] ?? '0'),
                'buffalo' => (string) ($row['buffalo'] ?? '0'),
                'notes' => filled($row['notes'] ?? null) ? (string) $row['notes'] : null,
            ];
        }

        return $rows;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $attributes = ['production_date' => __('milk.fields.date')];

        foreach (Shift::cases() as $shift) {
            $attributes["shifts.{$shift->value}.cow"] = $shift->label().' — '.__('milk.milk_types.cow');
            $attributes["shifts.{$shift->value}.buffalo"] = $shift->label().' — '.__('milk.milk_types.buffalo');
            $attributes["shifts.{$shift->value}.notes"] = $shift->label().' — '.__('milk.fields.notes');
        }

        return $attributes;
    }
}
