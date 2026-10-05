<?php

declare(strict_types=1);

use App\Enums\TransactionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit corrections to what a buyer owes.
 *
 * The third term of the outstanding formula, which Phase 4 carried as a named zero
 * (MASTER_SPEC section 27):
 *
 *     outstanding = sales + receivable adjustments - payments received
 *
 * It exists so that a Mandali statement differing from the system figure can be
 * accounted for **without rewriting history**. The specification is explicit: do not
 * silently modify historical milk rates; record an explicit adjustment with an
 * amount, a reason, a settlement reference, a creator and a timestamp. Those are the
 * columns below.
 *
 * ## What this is not
 *
 *  - **Not cash.** It posts nothing to a financial account and appears in no
 *    cashbook. Money arriving is a `buyer_payment`; this is a correction to what was
 *    owed in the first place.
 *  - **Not milk.** It changes no litre, no reconciliation figure and no sale. A
 *    settlement difference is a disagreement about money, not about quantity — and
 *    if the quantity really is wrong, the sale is corrected and the settlement
 *    redone.
 *  - **Not a hidden balancing row.** Nothing creates one automatically to make a
 *    total come out even. Every row has a stated reason and an actor, and the only
 *    thing that creates one in Pass 1 is settlement finalization, where the reason
 *    is the settlement.
 *
 * Direction plus a positive amount, never a signed amount, for the reason recorded
 * in D31: a form asking for "-300.00 rupees" and a list showing negative money read
 * badly, and a validation rule permitting negative money in exactly one table is one
 * somebody will relax by mistake.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_balance_adjustments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('buyer_id')->constrained()->restrictOnDelete();

            $table->date('adjustment_date');

            // increase raises what the buyer owes; decrease lowers it.
            $table->string('direction', 20);

            // Always positive. The direction carries the sign.
            $table->decimal('amount', 14, 2);

            // Required by the specification, and required here rather than only in a
            // Form Request: an adjustment nobody explained is indistinguishable from
            // a mistake.
            $table->text('reason');

            /*
             * The settlement this correction came out of, when it came out of one.
             *
             * Nullable because a manual adjustment is a legitimate future workflow
             * that has no settlement behind it. `cascadeOnDelete` is deliberately not
             * used: settlements are cancelled, never deleted, and an adjustment must
             * outlive any attempt to remove its settlement row.
             */
            $table->foreignId('buyer_settlement_id')->nullable()
                ->constrained('buyer_settlements')->restrictOnDelete();

            $table->string('status', 20)->default(TransactionStatus::Active->value);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The outstanding calculation's lookup, and the buyer's history.
            $table->index(['buyer_id', 'status', 'adjustment_date'], 'buyer_balance_adjustments_lookup_index');

            /*
             * **At most one active adjustment per settlement.**
             *
             * Finalizing a settlement creates exactly one adjustment, and finalizing
             * is refused once a settlement is already finalized — but a retry racing
             * itself would slip past an application check, and the result would be a
             * receivable corrected twice for one disagreement. The database refuses
             * it instead.
             *
             * Conditional on the status, using the same generated-column technique as
             * `milk_sales.daily_grid_key` (D38), because MySQL has no partial unique
             * indexes and a cancelled adjustment must not block a replacement.
             */
            $table->string('active_settlement_key', 191)->nullable()->virtualAs(
                "CASE WHEN `status` = '".TransactionStatus::Active->value."'"
                .' AND `buyer_settlement_id` IS NOT NULL'
                .' THEN CAST(`buyer_settlement_id` AS CHAR) ELSE NULL END'
            );

            $table->unique('active_settlement_key', 'buyer_balance_adjustments_settlement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_balance_adjustments');
    }
};
