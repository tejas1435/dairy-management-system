<?php

use App\Enums\TransactionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money a partner puts into a business account (MASTER_SPEC section 31).
 *
 * Saving one credits the destination account through the ledger, so the
 * contribution shows up in the partner ledger, the cashbook and the account
 * balance without anything being entered twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->date('contribution_date');
            $table->decimal('amount', 14, 2);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            $table->string('status', 20)->default(TransactionStatus::Active->value);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['partner_id', 'contribution_date']);
            // Named explicitly: the generated name would exceed MySQL's
            // 64-character identifier limit.
            $table->index(['financial_account_id', 'contribution_date'], 'partner_contributions_account_date_index');
            $table->index(['status', 'contribution_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_contributions');
    }
};
