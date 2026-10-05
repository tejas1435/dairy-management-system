<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which milk a direct customer takes, and roughly how much (MASTER_SPEC
 * section 16).
 *
 * One row per customer and milk type, so a customer may take cow milk, buffalo
 * milk or both. There are deliberately no `cow_*` / `buffalo_*` columns on
 * `buyers`: a third milk type would then be a schema change to the buyer table,
 * and a customer who takes only buffalo would carry meaningless cow columns.
 *
 * **The reminder quantities are reminders.** They are displayed beside the daily
 * entry fields as helper text and are never a default value, a contractual
 * minimum, a delivery limit or an automatic order. Nothing in the application may
 * read them as the quantity actually delivered — see docs/DECISIONS.md D39. The
 * column names say `reminder` for that reason, and no method anywhere returns one
 * as a sale quantity.
 *
 * `is_active` lets a customer stop taking one milk type without the row being
 * deleted, which keeps the historical sales explainable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_preferences', function (Blueprint $table) {
            $table->id();

            /*
             * Cascades: a preference is part of the customer record rather than a
             * transaction, so it has no meaning once the buyer is gone. Buyers are
             * deactivated rather than deleted in practice, so this is a
             * last-resort tidy-up, not an expected path.
             */
            $table->foreignId('buyer_id')->constrained()->cascadeOnDelete();

            $table->string('milk_type', 20);

            // Litres to three decimal places, as everywhere else milk is measured.
            $table->decimal('morning_reminder_qty', 10, 3)->default(0);
            $table->decimal('evening_reminder_qty', 10, 3)->default(0);

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One preference per customer per milk type.
            $table->unique(['buyer_id', 'milk_type']);
            $table->index(['buyer_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_preferences');
    }
};
