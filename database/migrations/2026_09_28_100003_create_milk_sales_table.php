<?php

use App\Enums\SaleSource;
use App\Enums\TransactionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The canonical milk distribution transaction (MASTER_SPEC sections 20 and 25).
 *
 * One table for every channel. Direct customer deliveries write to it in Phase 4;
 * Mandali, vendor and custom-channel sales write to the same table in Phase 5 with
 * no schema change. That is why it carries `sales_channel_id` and a generic
 * `source` rather than anything customer-shaped.
 *
 * `unit_rate` is a **snapshot** taken from the price resolver at save time. A sale
 * dated in September keeps September's rate however often the price changes
 * afterwards, and `amount` is computed server-side as quantity x rate rather than
 * trusted from the browser.
 *
 * `fat_percentage` and `snf_percentage` are the two channel-specific columns the
 * Phase 0 design allocated here deliberately, because a Mandali delivery records
 * them against the sale itself. Both are nullable and untouched by Phase 4; V1 has
 * no fat/SNF pricing formula, so they are reference data for reporting only
 * (MASTER_SPEC section 17).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_sales', function (Blueprint $table) {
            $table->id();

            $table->foreignId('farm_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_channel_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained()->restrictOnDelete();

            $table->date('sale_date');
            $table->string('shift', 20);
            $table->string('milk_type', 20);

            $table->decimal('quantity', 10, 3);
            $table->decimal('unit_rate', 10, 2);
            $table->decimal('amount', 14, 2);

            // Reference only in V1; no pricing formula reads them.
            $table->decimal('fat_percentage', 5, 2)->nullable();
            $table->decimal('snf_percentage', 5, 2)->nullable();

            // Which workflow produced this row. See App\Enums\SaleSource.
            $table->string('source', 40)->default(SaleSource::CustomerDailyGrid->value);

            $table->text('notes')->nullable();

            $table->string('status', 20)->default(TransactionStatus::Active->value);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * Idempotency for the customer daily grid.
             *
             * MASTER_SPEC section 20 requires re-saving a day to update the existing
             * sales rather than duplicate them, for the identity
             * customer + date + shift + milk type + grid source. MySQL has no
             * partial unique indexes, so a plain unique key on those columns would
             * also constrain every other source -- and a Phase 5 generic sale form
             * may legitimately record two separate vendor sales in one shift.
             *
             * This generated column is the identity **only for grid rows** and NULL
             * for every other source. MySQL treats NULLs as distinct in a unique
             * index, so grid saves are exactly idempotent while nothing else is
             * restricted. It is the same technique the one-primary-farm-per-business
             * constraint uses (docs/DECISIONS.md D16), and it is VIRTUAL for the same
             * reason: MySQL forbids some referential actions on the base columns of a
             * stored generated column.
             *
             * farm_id is part of the identity although MASTER_SPEC names only
             * customer/date/shift/milk type. Every operational record carries a farm
             * and more farms are supported, so two farms must be able to deliver to
             * the same customer on the same day without colliding. See
             * docs/DECISIONS.md D38.
             */
            $table->string('daily_grid_key', 191)->nullable()->virtualAs(
                "CASE WHEN `source` = '".SaleSource::CustomerDailyGrid->value."'"
                ." THEN CONCAT_WS('|', `farm_id`, `buyer_id`, `sale_date`, `shift`, `milk_type`)"
                .' ELSE NULL END'
            );

            $table->unique('daily_grid_key', 'milk_sales_daily_grid_unique');

            // Reconciliation: one farm, one date, one shift, one milk type.
            $table->index(
                ['farm_id', 'sale_date', 'shift', 'milk_type'],
                'milk_sales_reconciliation_index'
            );
            // The customer ledger, and the channel breakdown the screen shows.
            $table->index(['buyer_id', 'sale_date']);
            $table->index(['sales_channel_id', 'sale_date']);
            $table->index(['status', 'sale_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_sales');
    }
};
