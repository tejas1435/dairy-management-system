<?php

use App\Enums\FinancialAccountType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business cash and bank accounts, as bookkeeping records inside this
 * application (MASTER_SPEC section 28).
 *
 * Note what is absent: there is no `current_balance` column, and there never
 * will be. A balance is opening_balance + credits - debits, derived from
 * financial_ledger_entries. A stored balance can drift from its own ledger and
 * gives no way to tell which one is wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20)->default(FinancialAccountType::Cash->value);
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'name']);
            $table->index(['business_id', 'is_active']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_accounts');
    }
};
