<?php

use App\Enums\TransactionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money received from a buyer (MASTER_SPEC section 26).
 *
 * **Accounting only.** Nothing here processes a payment: the user records how money
 * that has already arrived was received. The application connects to no gateway,
 * UPI API or bank.
 *
 * Generic from the start, like `milk_sales`. Phase 4 records direct customer
 * payments; Phase 5 records Mandali and vendor payments against the same table.
 *
 * Saving one credits the destination financial account through the ledger, so the
 * payment shows up in the customer's outstanding, the cashbook and the account
 * balance from a single entry. Cancelling it posts one reversing debit and leaves
 * both entries visible (docs/DECISIONS.md D21).
 *
 * There is no `outstanding` column anywhere — on this table or on `buyers`.
 * Outstanding is sales plus receivable adjustments minus payments, derived on read
 * (MASTER_SPEC section 27).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('buyer_id')->constrained()->restrictOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 14, 2);

            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();

            // The business account the money landed in. Restricted: an account
            // referenced by a payment cannot be deleted from under it.
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();

            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            $table->string('status', 20)->default(TransactionStatus::Active->value);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['buyer_id', 'payment_date']);
            $table->index(['financial_account_id', 'payment_date'], 'buyer_payments_account_date_index');
            $table->index(['status', 'payment_date']);
        });

        /*
         * `buyer_settlement_id` is deliberately absent.
         *
         * MASTER_SPEC section 26 lists an optional settlement reference, and
         * docs/DATABASE.md has this table depending on `buyer_settlements` — but that
         * table is a Phase 5 concern (Mandali settlement) and does not exist yet. A
         * nullable column with no table behind it would be a foreign key pointing at
         * nothing, which is exactly the defect the employee-loan alias correction
         * caught in Phase 0 (docs/DECISIONS.md D13). Phase 5 adds the column and its
         * constraint together.
         */
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_payments');
    }
};
