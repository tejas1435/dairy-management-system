<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer-specific milk price overrides, effective-dated.
 *
 * A buyer with no rule for a date falls back to the business default. Overrides
 * are never created automatically for every buyer -- absence is the normal case
 * and means "use the default".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_price_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained()->cascadeOnDelete();
            $table->string('milk_type', 20);
            $table->decimal('rate', 10, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['buyer_id', 'milk_type', 'effective_from'], 'buyer_price_rules_period_unique');
            $table->index(['buyer_id', 'milk_type', 'effective_from', 'effective_to'], 'buyer_price_rules_resolution_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_price_rules');
    }
};
