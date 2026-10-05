<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Farms/locations. V1 exposes one primary farm in operational workflows, but
 * every operational record carries farm_id from the start so additional
 * locations need no redesign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20);
            $table->text('address')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'is_active']);

            /*
             * Enforces "at most one primary farm per business" in the database.
             *
             * A unique index on (business_id, is_primary) would be wrong: it
             * would also forbid a second NON-primary farm, because two rows
             * would share (business_id, false). This generated column is the
             * business_id only while is_primary is true, and NULL otherwise.
             * MySQL treats NULLs as distinct in a unique index, so any number of
             * non-primary farms is allowed while a duplicate primary is rejected
             * by the engine rather than by convention.
             *
             * VIRTUAL rather than STORED on purpose: MySQL forbids ON DELETE
             * CASCADE on a base column of a STORED generated column, which would
             * make the business_id foreign key above impossible to create.
             * Virtual columns carry no such restriction and still support the
             * unique secondary index that does the work.
             *
             * Defined last so the columns it references already exist.
             */
            $table->unsignedBigInteger('primary_farm_lock')
                ->virtualAs('case when `is_primary` = 1 then `business_id` else null end');

            $table->unique('primary_farm_lock');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farms');
    }
};
