<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business default milk prices, effective-dated (MASTER_SPEC sections 17, 70).
 *
 * Changing a price never overwrites a row. The current period is closed by
 * setting effective_to, and a new row opens, so September stays priced at
 * September rates however many times the price changes afterwards.
 *
 * MySQL cannot express "no two periods for the same business and milk type may
 * overlap" as a constraint, so the rule is enforced by a domain action holding a
 * row lock. The unique index below covers the part the database *can* enforce:
 * two rules for the same business, milk type and start date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_price_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('milk_type', 20);
            $table->decimal('rate', 10, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'milk_type', 'effective_from'], 'milk_price_rules_period_unique');
            $table->index(['business_id', 'milk_type', 'effective_from', 'effective_to'], 'milk_price_rules_resolution_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_price_rules');
    }
};
