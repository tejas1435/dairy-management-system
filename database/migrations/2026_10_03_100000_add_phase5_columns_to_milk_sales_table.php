<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Phase 5 additions to the canonical sale.
 *
 * Deliberately four nullable columns on `milk_sales` rather than a companion table.
 * A `mandali_sales` table would hold a second copy of the same sale facts — buyer,
 * date, shift, quantity, rate, amount — and every query that totals milk or money
 * would have to remember to union it. One canonical sale per delivery is the whole
 * design (MASTER_SPEC section 25: "all sales must feed the same reporting and
 * milk-reconciliation engine").
 *
 * `fat_percentage` and `snf_percentage` are **not** added here. They were created
 * with the table in Phase 4, nullable and unused, precisely so that Phase 5 would
 * not have to alter a table holding live sales to record milk quality.
 *
 * What is new:
 *
 *  - the optional Mandali collection slip, stored on the private disk with a
 *    randomised name and its original filename kept only as metadata;
 *  - the rate the resolver would have returned, when a vendor sale departs from it,
 *    with the reason — so an override is legible on the row itself and not only in
 *    the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milk_sales', function (Blueprint $table): void {
            /*
             * The stored path, which is a randomised name under a private disk
             * directory. Never a URL, and never anything derived from what the user
             * called the file.
             */
            $table->string('slip_path')->nullable()->after('snf_percentage');

            // Shown to the operator on download. Metadata only: nothing resolves a
            // filesystem path from it.
            $table->string('slip_name')->nullable()->after('slip_path');

            /*
             * What `PriceResolver` said for this buyer, milk type and sale date at
             * the time of the write, when the applied rate differs from it.
             *
             * Null means "no override happened here" — either the applied rate *is*
             * the resolved one, or the workflow sets its own rate by design (Mandali,
             * generic). It is a historical note beside `unit_rate`, not a second
             * source of truth: `amount` is always computed from `unit_rate`.
             */
            $table->decimal('resolved_rate', 10, 2)->nullable()->after('unit_rate');

            // Required when a vendor rate is overridden; an override with no stated
            // reason is indistinguishable from a typo six months later.
            $table->text('rate_override_reason')->nullable()->after('resolved_rate');

            /*
             * The Phase 5 list pages are per workflow and paginated by date, so they
             * all filter on exactly this pair.
             */
            $table->index(['source', 'sale_date'], 'milk_sales_source_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('milk_sales', function (Blueprint $table): void {
            $table->dropIndex('milk_sales_source_date_index');
            $table->dropColumn(['slip_path', 'slip_name', 'resolved_rate', 'rate_override_reason']);
        });
    }
};
