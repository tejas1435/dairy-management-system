<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a receipt to the settlement it pays off.
 *
 * Phase 4 deliberately left this column out. The plan had it from the start, but
 * `buyer_settlements` did not exist, and a foreign key pointing at a missing table
 * is the defect D13 exists to prevent — so it was documented as arriving with the
 * table it references, which is this migration.
 *
 * Nullable, and it stays nullable: a direct customer pays a running balance with no
 * settlement involved, and a Mandali may also pay on account. The link is what lets
 * a settlement's two payment statuses be derived from the receipts against it rather
 * than stored and kept in step by hand.
 *
 * `restrictOnDelete` for the same reason as everywhere else in this schema: a
 * settlement is cancelled, never deleted, and a receipt must not be removable as a
 * side effect of anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyer_payments', function (Blueprint $table): void {
            $table->foreignId('buyer_settlement_id')->nullable()->after('buyer_id')
                ->constrained('buyer_settlements')->restrictOnDelete();

            // Summing the active receipts against one settlement, which is how its
            // Partially Paid and Paid states are worked out.
            $table->index(['buyer_settlement_id', 'status'], 'buyer_payments_settlement_index');
        });
    }

    public function down(): void
    {
        Schema::table('buyer_payments', function (Blueprint $table): void {
            $table->dropIndex('buyer_payments_settlement_index');
            $table->dropForeign(['buyer_settlement_id']);
            $table->dropColumn('buyer_settlement_id');
        });
    }
};
