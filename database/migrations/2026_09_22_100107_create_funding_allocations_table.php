<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reusable multi-source payment mechanism (MASTER_SPEC section 32).
 *
 * Any payable can be funded by any number of sources. Phase 2 funds expenses;
 * Phase 6 adds animal purchases and Phase 7 adds payroll payments and loan
 * disbursements through this same table, with no schema change.
 *
 * Both polymorphic columns store stable morph aliases -- `expense`, `partner`,
 * `financial_account` -- never PHP class names.
 *
 * The invariant that matters, enforced in the domain service inside the same
 * transaction: SUM(amount) for a payable equals that payable's amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funding_allocations', function (Blueprint $table) {
            $table->id();

            $table->string('payable_type', 60);
            $table->unsignedBigInteger('payable_id');

            $table->string('source_type', 60);
            $table->unsignedBigInteger('source_id');

            $table->decimal('amount', 14, 2);
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['payable_type', 'payable_id'], 'funding_allocations_payable_index');
            $table->index(['source_type', 'source_id'], 'funding_allocations_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_allocations');
    }
};
