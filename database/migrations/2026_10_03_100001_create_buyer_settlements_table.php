<?php

declare(strict_types=1);

use App\Enums\SettlementStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mandali settlement for a period (MASTER_SPEC section 23).
 *
 * A Mandali settles monthly rather than per delivery: the dairy sends a statement,
 * it is compared against what the system recorded, and the difference is accounted
 * for explicitly. This table is the record of that comparison.
 *
 * ## Everything numeric here is a snapshot
 *
 * `milk_quantity` and `expected_amount` are **computed at finalization and then
 * frozen**. They are not a cache of a query that could be re-run — they are what was
 * agreed, on the figures that existed when it was agreed. Recomputing them later
 * would quietly change an agreed settlement because somebody corrected an unrelated
 * sale, and the adjustment already posted against the old figures would no longer
 * reconcile with anything.
 *
 * This is the same reasoning that keeps `milk_sales.unit_rate` a snapshot (D41), one
 * level up.
 *
 * ## No stored balance
 *
 * There is no `paid_amount` column. What has been received is the sum of active
 * `buyer_payments` linked to this settlement, and the two payment statuses derive
 * from that sum — so a stored total cannot drift from the receipts, and a cancelled
 * receipt moves the status back on its own. Consistent with every other balance in
 * this project.
 *
 * Belongs to a buyer rather than to a Mandali, because the buyer table serves every
 * channel (D26). Nothing in the schema restricts it to the Mandali channel; the
 * domain action does, because a channel is data and a foreign key cannot express
 * "only rows whose channel slug is mandali".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_settlements', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('buyer_id')->constrained()->restrictOnDelete();

            // Business dates, inclusive at both ends, like every period in this
            // schema (D7).
            $table->date('period_start');
            $table->date('period_end');

            /*
             * Snapshots, taken at finalization. Null while the settlement is a draft:
             * a draft has agreed nothing, and a zero would read as "no milk" rather
             * than "not yet established" — the same distinction Phase 3 draws between
             * missing production and a recorded zero (D36).
             */
            $table->decimal('milk_quantity', 10, 3)->nullable();
            $table->decimal('expected_amount', 14, 2)->nullable();

            /*
             * What the Mandali's own statement says, when one has been received.
             * Optional: a settlement may be agreed on the system figures alone, and
             * then there is no difference to account for.
             */
            $table->decimal('statement_amount', 14, 2)->nullable();

            /*
             * statement_amount - expected_amount, stored because it is the figure the
             * adjustment was created from. Signed here, unlike the adjustment itself,
             * because this is arithmetic rather than a thing a user chose a direction
             * for.
             */
            $table->decimal('difference', 14, 2)->nullable();

            $table->string('status', 30)->default(SettlementStatus::Draft->value);

            $table->text('notes')->nullable();

            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The buyer's settlement history, newest first, and the overlap check.
            $table->index(['buyer_id', 'status', 'period_start', 'period_end'], 'buyer_settlements_period_index');
            $table->index(['status', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_settlements');
    }
};
