<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The account ledger (MASTER_SPEC section 29).
 *
 * Append-only. Entries are written by domain services, never edited, and never
 * deleted. A posting that turns out to be wrong is undone by a reversal entry
 * in the opposite direction, so the history of what was believed at the time
 * survives alongside the correction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->date('entry_date');
            $table->string('direction', 10);
            $table->decimal('amount', 14, 2);

            // Stable morph alias plus id of the record that caused this entry.
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->string('description');

            /*
             * Idempotency guard.
             *
             * Every posting derives a deterministic key from the domain
             * operation that caused it, for example
             * "expense_funding:{allocation_id}" or
             * "partner_contribution_reversal:{contribution_id}". The unique
             * index means a retried request, a double-clicked button or a
             * repeated job cannot post the same effect twice: the second attempt
             * hits the constraint instead of silently doubling a balance.
             */
            $table->string('idempotency_key', 191)->unique();

            /*
             * Set on a reversal, pointing at the entry it cancels. Unique, so an
             * entry can be reversed at most once -- the database refuses a
             * double reversal rather than relying on the caller to check.
             */
            $table->foreignId('reverses_entry_id')->nullable()
                ->constrained('financial_ledger_entries')->nullOnDelete();
            $table->unique('reverses_entry_id');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['financial_account_id', 'entry_date']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('entry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_ledger_entries');
    }
};
