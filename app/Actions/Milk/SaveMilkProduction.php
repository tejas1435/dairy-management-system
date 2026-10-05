<?php

declare(strict_types=1);

namespace App\Actions\Milk;

use App\Enums\Shift;
use App\Models\MilkProduction;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Records what a farm produced, for one shift or for a whole day.
 *
 * The screen presents a matrix -- cow and buffalo down, morning and evening
 * across -- while the table holds one row per shift. Saving the screen therefore
 * touches up to two rows, and `forDay()` does both inside one transaction so a
 * day cannot end up with its morning saved and its evening lost.
 *
 * **Saving the same shift twice updates one row.** The farm, date and shift
 * identify the record, so this is an upsert on that identity rather than an
 * insert. The unique index on those three columns is the guarantee; this method is
 * the path that never needs it.
 *
 * `created_by` is written once, at first entry, and `updated_by` on every later
 * save. Overwriting `created_by` on a correction would lose who originally
 * recorded the shift, which is usually the more interesting of the two.
 */
class SaveMilkProduction
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessContext $context,
    ) {}

    /**
     * Saves both shifts of one date in a single transaction.
     *
     * @param  array<string, array{cow: string, buffalo: string, notes?: string|null}>  $shifts
     *                                                                                           keyed by Shift value; a shift left out is not touched
     * @return array<string, MilkProduction> saved records, keyed by shift value
     */
    public function forDay(string $date, array $shifts, ?int $farmId = null): array
    {
        $farmId ??= $this->context->primaryFarmId();

        return DB::transaction(function () use ($date, $shifts, $farmId): array {
            $saved = [];

            foreach (Shift::cases() as $shift) {
                if (! array_key_exists($shift->value, $shifts)) {
                    continue;
                }

                $row = $shifts[$shift->value];

                $saved[$shift->value] = $this->persist(
                    farmId: $farmId,
                    date: $date,
                    shift: $shift,
                    cow: Quantity::of($row['cow'] ?? null),
                    buffalo: Quantity::of($row['buffalo'] ?? null),
                    notes: $row['notes'] ?? null,
                );
            }

            return $saved;
        });
    }

    /** Saves a single shift. */
    public function forShift(
        string $date,
        Shift $shift,
        string $cow,
        string $buffalo,
        ?string $notes = null,
        ?int $farmId = null,
    ): MilkProduction {
        $farmId ??= $this->context->primaryFarmId();

        return DB::transaction(fn (): MilkProduction => $this->persist(
            farmId: $farmId,
            date: $date,
            shift: $shift,
            cow: Quantity::of($cow),
            buffalo: Quantity::of($buffalo),
            notes: $notes,
        ));
    }

    /**
     * Creates or updates the one row for this farm, date and shift.
     *
     * Runs inside the caller's transaction and takes a row lock on the existing
     * record, so two people saving the same shift at once serialise rather than
     * both reading "no row yet" and both inserting.
     */
    private function persist(
        int $farmId,
        string $date,
        Shift $shift,
        string $cow,
        string $buffalo,
        ?string $notes,
    ): MilkProduction {
        $existing = MilkProduction::query()
            ->forShift($farmId, $date, $shift)
            ->lockForUpdate()
            ->first();

        if (! $existing) {
            $production = MilkProduction::create([
                'farm_id' => $farmId,
                'production_date' => $date,
                'shift' => $shift->value,
                'cow_milk_quantity' => $cow,
                'buffalo_milk_quantity' => $buffalo,
                'notes' => $notes,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $this->audit->created($production, [
                'production_date' => $date,
                'shift' => $shift->value,
                'cow_milk_quantity' => $cow,
                'buffalo_milk_quantity' => $buffalo,
            ], $this->subject($production));

            return $production;
        }

        $before = [
            'cow_milk_quantity' => Quantity::of($existing->cow_milk_quantity),
            'buffalo_milk_quantity' => Quantity::of($existing->buffalo_milk_quantity),
            'notes' => $existing->notes,
        ];

        $existing->forceFill([
            'cow_milk_quantity' => $cow,
            'buffalo_milk_quantity' => $buffalo,
            'notes' => $notes,
            'updated_by' => Auth::id(),
        ])->save();

        /*
         * updated() records only what actually differs and returns null when
         * nothing did, so re-saving the screen without edits adds no audit noise.
         */
        $this->audit->updated($existing, $before, [
            'cow_milk_quantity' => $cow,
            'buffalo_milk_quantity' => $buffalo,
            'notes' => $notes,
        ], $this->subject($existing));

        return $existing->refresh();
    }

    /** A label the audit viewer can read without loading the record. */
    private function subject(MilkProduction $production): string
    {
        return $production->production_date->format('d-m-Y').' — '.$production->shift->label();
    }
}
