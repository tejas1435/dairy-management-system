<?php

use App\Enums\TransactionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A period during which a direct customer takes no milk (MASTER_SPEC section 18).
 *
 * A holiday, a temporary stop, or simply no milk wanted for a fortnight. During a
 * pause the customer is marked on the daily entry grid, their quantity fields are
 * disabled, and **no sale is generated through the normal workflow** — the server
 * refuses one, not just the UI.
 *
 * `end_date` is nullable, meaning open-ended: paused until somebody says
 * otherwise. `reason` is optional, because the specification says so; a customer
 * going away for a week needs no justification recorded.
 *
 * Pauses are **cancelled rather than deleted**. A pause that stopped deliveries
 * for a week is part of why that week has no sales, so withdrawing it should read
 * as a withdrawal rather than erase the explanation. Overlap between active pauses
 * is prevented by a domain action holding a row lock — MySQL cannot express "no
 * two periods for the same customer may overlap" as a constraint, the same
 * limitation the milk price periods work around (docs/DECISIONS.md D27).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained()->cascadeOnDelete();

            $table->date('start_date');
            // Null means open-ended.
            $table->date('end_date')->nullable();

            // Optional by specification.
            $table->text('reason')->nullable();

            $table->string('status', 20)->default(TransactionStatus::Active->value);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * The "is this customer paused on this date" lookup, which the daily
             * entry grid runs once per customer per page.
             */
            $table->index(['buyer_id', 'status', 'start_date', 'end_date'], 'customer_pauses_lookup_index');
            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_pauses');
    }
};
