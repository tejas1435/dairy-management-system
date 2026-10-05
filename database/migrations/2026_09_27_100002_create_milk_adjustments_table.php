<?php

use App\Enums\TransactionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authorised corrections to the milk available for a shift (MASTER_SPEC
 * section 15).
 *
 * **This is not a balancing table.** Nothing in the application creates a row
 * here to make a total come out even. An adjustment exists only because an
 * authorised person deliberately stated that the recorded production was not
 * what was actually available, and said why. The reason is mandatory for exactly
 * that purpose: an adjustment without one is indistinguishable from a mistake.
 *
 * `direction` plus a positive `quantity`, rather than one signed column. A signed
 * quantity would mean a form that asks for "-2.500 litres", a list that displays
 * negative litres, and a validation rule permitting negative milk in this one
 * table while every other milk column forbids it. See docs/DECISIONS.md D31.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->restrictOnDelete();
            $table->date('adjustment_date');
            $table->string('shift', 20);
            $table->string('milk_type', 20);

            // 'increase' adds to available milk, 'decrease' takes it away.
            $table->string('direction', 20);

            // Always positive. The direction carries the sign.
            $table->decimal('quantity', 10, 3);

            /*
             * Required at the database level too, not only in the Form Request.
             * This column is the entire justification for the row existing.
             */
            $table->text('reason');

            $table->string('status', 20)->default(TransactionStatus::Active->value);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['farm_id', 'adjustment_date', 'shift', 'milk_type'],
                'milk_adjustments_reconciliation_index'
            );
            $table->index(['status', 'adjustment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_adjustments');
    }
};
