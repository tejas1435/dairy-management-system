<?php

declare(strict_types=1);

namespace App\Actions\Milk;

use App\Enums\MilkType;
use App\Enums\Shift;
use App\Services\BusinessContext;
use App\Services\Milk\CustomerDailyEntryGrid;
use App\Support\Milk\DailyEntryRow;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Saves a whole day of the Customer Daily Entry grid as one business operation.
 *
 * The screen posts the entire day, so this is the action that turns a page of
 * quantities into sales. Each individual cell still goes through
 * {@see SaveCustomerDailySale}, which owns the create/update/cancel rules; nothing
 * here re-implements them. What this adds is everything that only makes sense for a
 * *day*: which cells actually changed, in what order they must be applied, who is
 * allowed to apply them, and the guarantee that the day lands whole or not at all.
 *
 * ## Why the order matters
 *
 * Availability is checked per cell against the milk still unallocated. Consider a
 * shift with 10.000 litres, fully allocated, where the operator moves 2.000 litres
 * from one customer to another in a single save. The day is perfectly legal — the
 * final allocation is still 10.000 — but processing the increase first fails, because
 * at that moment the milk is still booked to the other customer.
 *
 * So operations are applied in two phases: everything that **releases** milk
 * (cancellations and decreases) before everything that **claims** it (creations and
 * increases). Within each phase the order is buyer, then milk type, then shift —
 * deterministic, so two concurrent saves take row locks in the same sequence rather
 * than deadlocking against each other.
 *
 * This never lets a day through that should not pass. Each claim is still checked
 * against the live figures, and the last claim processed sees every other cell at
 * its final value, so a day whose total genuinely exceeds the milk is still refused.
 *
 * ## All or nothing
 *
 * One transaction around the lot. If the ninety-ninth cell is fine and the hundredth
 * is not, the first ninety-nine are rolled back with it — sales, cancellations and
 * audit records alike. A partially saved day is worse than a refused one: the
 * operator would have no way to tell which half went in.
 */
class SaveCustomerDailyDeliveries
{
    public const PERMISSION_VIEW = 'milk.customer_delivery.view';

    public const PERMISSION_CREATE = 'milk.customer_delivery.create';

    public const PERMISSION_UPDATE = 'milk.customer_delivery.update';

    public function __construct(
        private readonly CustomerDailyEntryGrid $grid,
        private readonly SaveCustomerDailySale $saveCell,
        private readonly BusinessContext $context,
    ) {}

    /**
     * Applies a day's worth of submitted quantities.
     *
     * @param  array<int, array{buyer_id: int, milk_type: string, morning: string|null, evening: string|null}>  $rows
     * @return array{changed: int, created: int, updated: int, cancelled: int}
     *
     * @throws ValidationException
     */
    public function handle(string $date, array $rows, ?int $farmId = null): array
    {
        $farmId ??= $this->context->primaryFarmId();

        /*
         * The authoritative rows, rebuilt from the database. The payload says which
         * cells the operator touched; it does not get to say who is eligible, what
         * the rate is or whether a sale already exists. A row the server does not
         * recognise is refused rather than ignored, because silently dropping it
         * would tell the operator their entry was saved when it was not.
         */
        $authoritative = $this->grid->rowsFor($date, $farmId)
            ->keyBy(fn (DailyEntryRow $row): string => $row->key());

        $operations = $this->planOperations($date, $rows, $authoritative);

        $this->assertPermitted($operations);

        if ($operations === []) {
            return ['changed' => 0, 'created' => 0, 'updated' => 0, 'cancelled' => 0];
        }

        return DB::transaction(function () use ($operations, $date, $farmId): array {
            $summary = ['changed' => 0, 'created' => 0, 'updated' => 0, 'cancelled' => 0];

            foreach ($operations as $operation) {
                /** @var DailyEntryRow $row */
                $row = $operation['row'];

                $this->saveCell->handle(
                    customer: $row->customer,
                    date: $date,
                    shift: $operation['shift'],
                    milkType: $row->milkType,
                    quantity: $operation['quantity'],
                    farmId: $farmId,
                );

                $summary['changed']++;
                $summary[$operation['kind']]++;
            }

            return $summary;
        });
    }

    /**
     * Works out what actually changed, and in what order it must be applied.
     *
     * @param  array<int, array{buyer_id: int, milk_type: string, morning: string|null, evening: string|null}>  $rows
     * @param  Collection<string, DailyEntryRow>  $authoritative
     * @return array<int, array{row: DailyEntryRow, shift: Shift, quantity: string|null, kind: string, phase: int, sort: array<int, int|string>}>
     *
     * @throws ValidationException
     */
    private function planOperations(string $date, array $rows, Collection $authoritative): array
    {
        $operations = [];
        $seen = [];

        foreach ($rows as $index => $submitted) {
            $milkType = MilkType::tryFrom((string) $submitted['milk_type']);

            if ($milkType === null) {
                throw ValidationException::withMessages([
                    "rows.{$index}.milk_type" => __('milk.customer_entry.errors.unknown_milk_type'),
                ]);
            }

            $key = ((int) $submitted['buyer_id']).':'.$milkType->value;

            /*
             * Two payload entries for the same customer and milk type would make the
             * result depend on which one happened to be applied last. That is not a
             * save the operator can have meant, so it is refused rather than
             * resolved by a rule nobody would be able to predict.
             */
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "rows.{$index}.buyer_id" => __('milk.customer_entry.errors.duplicate_row'),
                ]);
            }

            $seen[$key] = true;

            $row = $authoritative->get($key);

            if ($row === null) {
                throw ValidationException::withMessages([
                    "rows.{$index}.buyer_id" => __('milk.customer_entry.errors.row_not_eligible'),
                ]);
            }

            foreach (Shift::cases() as $shift) {
                $operation = $this->planCell($row, $shift, $submitted[$shift->value] ?? null);

                if ($operation !== null) {
                    $operations[] = $operation;
                }
            }
        }

        /*
         * Releases (phase 0) before claims (phase 1); within a phase, buyer then
         * milk type then shift. usort is not stable in every PHP build, so the sort
         * key carries enough to be a total order on its own.
         */
        usort($operations, fn (array $a, array $b): int => $a['phase'] <=> $b['phase']
            ?: $a['sort'] <=> $b['sort']);

        return $operations;
    }

    /**
     * Classifies one cell against what the database currently holds.
     *
     * Returns null when nothing needs doing — an untouched cell, or one re-submitted
     * with the quantity it already has. Skipping those is not an optimisation: a
     * no-op update would write an audit record saying a delivery changed when it did
     * not, and a day re-saved unchanged would fill the trail with noise.
     *
     * @return array{row: DailyEntryRow, shift: Shift, quantity: string|null, kind: string, phase: int, sort: array<int, int|string>}|null
     *
     * @throws ValidationException
     */
    private function planCell(DailyEntryRow $row, Shift $shift, ?string $submitted): ?array
    {
        $current = $row->savedQuantity($shift);
        $wanted = $submitted === null || trim($submitted) === '' ? null : Quantity::of($submitted);

        // Blank, zero and 0.000 all mean the same thing: no delivery.
        if ($wanted !== null && Quantity::isZero($wanted)) {
            $wanted = null;
        }

        if ($wanted !== null && Quantity::isNegative($wanted)) {
            throw ValidationException::withMessages([
                'rows' => __('milk.customer_entry.errors.negative_quantity', ['name' => $row->customer->name]),
            ]);
        }

        if ($current === null && $wanted === null) {
            return null;
        }

        if ($current !== null && $wanted !== null && Quantity::compare($current, $wanted) === 0) {
            return null;
        }

        /*
         * A paused row is read-only in the browser, and read-only here too. The
         * fields are disabled on screen, but a disabled input is a courtesy and this
         * is the rule: a hand-written payload gets the same refusal.
         *
         * Note this fires only for cells that would *change*. An unchanged historical
         * sale on a now-paused date is left exactly as it is, which is why the pause
         * check lives here rather than at the top of the row loop.
         */
        if ($row->isPaused()) {
            throw ValidationException::withMessages([
                'rows' => __('customers.errors.customer_paused', [
                    'name' => $row->customer->name,
                    'date' => Carbon::parse($row->eligibility->date)->format('d-m-Y'),
                ]),
            ]);
        }

        $kind = match (true) {
            $wanted === null => 'cancelled',
            $current === null => 'created',
            default => 'updated',
        };

        /*
         * Phase 0 releases milk back to the shift, phase 1 claims it. A cancellation
         * and a decrease both release; a creation and an increase both claim.
         */
        $phase = match (true) {
            $wanted === null => 0,
            $current !== null && Quantity::compare($wanted, $current) < 0 => 0,
            default => 1,
        };

        return [
            'row' => $row,
            'shift' => $shift,
            'quantity' => $wanted,
            'kind' => $kind,
            'phase' => $phase,
            'sort' => [$row->customer->getKey(), $row->milkType->value, $shift->value],
        ];
    }

    /**
     * Checks the actor holds every permission the submitted operations need.
     *
     * Reaching the route proves only that the person may *see* the grid. A single
     * save can contain both a brand-new delivery and an edit to an existing one, and
     * those are separate permissions deliberately: an operator may be trusted to
     * enter today's round without being trusted to rewrite yesterday's.
     *
     * Checked for the whole request before anything is written, so a mixed save that
     * the actor is only half entitled to make is refused outright rather than applied
     * in part.
     *
     * @param  array<int, array{kind: string, ...}>  $operations
     *
     * @throws ValidationException
     */
    private function assertPermitted(array $operations): void
    {
        $needed = [];

        foreach ($operations as $operation) {
            // Removing or changing an existing delivery is a correction to the day.
            $needed[$operation['kind'] === 'created' ? self::PERMISSION_CREATE : self::PERMISSION_UPDATE] = true;
        }

        foreach (array_keys($needed) as $permission) {
            if (Gate::denies($permission)) {
                throw ValidationException::withMessages([
                    'rows' => __('milk.customer_entry.errors.not_permitted'),
                ]);
            }
        }
    }
}
