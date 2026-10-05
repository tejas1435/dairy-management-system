<?php

use App\Enums\TransactionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business expenses (MASTER_SPEC section 34).
 *
 * Exactly one row per real-world expense, whatever mix of partners and accounts
 * paid for it. The split lives in funding_allocations and is never counted as
 * additional expense: a 10,000 feed expense funded 7,000 by a partner and 3,000
 * from cash is one 10,000 row here and two allocation rows there.
 *
 * `animal_id` and `employee_id` are deliberately absent. Those tables do not
 * exist yet; later migrations in Phases 6 and 7 add the nullable foreign keys
 * when their models arrive, rather than creating empty tables now to satisfy a
 * column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->date('expense_date');
            $table->decimal('amount', 14, 2);
            $table->string('description');
            $table->string('payee_name')->nullable();
            $table->text('notes')->nullable();

            $table->string('status', 20)->default(TransactionStatus::Active->value);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'expense_date']);
            $table->index(['expense_category_id', 'expense_date']);
            $table->index(['status', 'expense_date']);
            $table->index('expense_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
