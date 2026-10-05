<?php

use App\Enums\TransactionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milk consumed internally rather than sold (MASTER_SPEC section 15).
 *
 * Calf feeding, home use, samples, wastage and anything else that takes milk out
 * of the available pool without a buyer. Reconciliation counts these alongside
 * sales because the milk is equally gone, but they are emphatically not sales:
 * recording a calf feeding as a zero-rate sale would invent a buyer, a
 * receivable and a line in the revenue reports.
 *
 * Unlike production, this table holds one row per usage event, and a shift can
 * have many. It is therefore keyed by milk type, because a single event concerns
 * one type of milk.
 *
 * Usage is cancelled, never deleted: it has already affected a reconciliation
 * somebody may have acted on (MASTER_SPEC section 60).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->restrictOnDelete();
            $table->date('usage_date');
            $table->string('shift', 20);
            $table->string('milk_type', 20);
            $table->string('usage_type', 30);
            $table->decimal('quantity', 10, 3);
            $table->text('notes')->nullable();

            $table->string('status', 20)->default(TransactionStatus::Active->value);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The reconciliation lookup: one farm, one date, one shift, one type.
            $table->index(
                ['farm_id', 'usage_date', 'shift', 'milk_type'],
                'milk_usages_reconciliation_index'
            );
            $table->index(['status', 'usage_date']);
            $table->index(['usage_type', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_usages');
    }
};
