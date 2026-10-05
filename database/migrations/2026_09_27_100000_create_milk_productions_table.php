<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily milk production, date-wise and shift-wise (MASTER_SPEC section 14).
 *
 * **One row per farm, date and shift, carrying both milk types.** Cow and
 * buffalo quantities are columns on the same row, not separate rows with a
 * `milk_type` discriminator. That is what the specification asks for, and it is
 * what makes the unique key below able to say "this shift has been recorded" as
 * a single fact rather than as a per-type coincidence. See docs/DECISIONS.md D30.
 *
 * There is deliberately no `milk_type` column here.
 *
 * The absence of a row is meaningful: it means production has not been entered,
 * which is a different business fact from a recorded zero. Nothing in this schema
 * or the code above it is allowed to collapse the two (MASTER_SPEC section 15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_productions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->restrictOnDelete();
            $table->date('production_date');
            $table->string('shift', 20);

            /*
             * Both types on one row. Defaulting to zero is correct here and is
             * not the same as "not entered": once a row exists, somebody has
             * stated what the shift produced, and stating zero cow milk is a
             * legitimate thing to state.
             */
            $table->decimal('cow_milk_quantity', 10, 3)->default(0);
            $table->decimal('buffalo_milk_quantity', 10, 3)->default(0);

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * The database-level guarantee against duplicate production. Saving
             * the same shift twice updates one row; it cannot produce a second.
             */
            $table->unique(['farm_id', 'production_date', 'shift'], 'milk_productions_farm_date_shift_unique');

            /*
             * Date-range reporting across shifts. farm_id and
             * (farm_id, production_date) are already served by the leftmost
             * columns of the unique index above, and a standalone index on
             * `shift` would never be chosen -- it has two distinct values.
             */
            $table->index(['production_date', 'shift']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_productions');
    }
};
