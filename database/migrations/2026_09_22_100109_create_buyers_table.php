<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The generic buyer identity (MASTER_SPEC section 12).
 *
 * One table serves a Mandali, a vendor, a direct customer and any future
 * channel. There are deliberately no parallel mandalis/vendors/customers
 * tables: they would triple the identity, payment and outstanding logic for no
 * gain. Channel-specific extension data, when a later phase needs it, attaches
 * to this row.
 *
 * Outstanding balance is absent on purpose. It is derived from sales, payments
 * and adjustments, never stored (MASTER_SPEC section 27).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_channel_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('mobile', 20)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('area')->nullable();
            $table->string('payment_cycle', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'sales_channel_id', 'is_active'], 'buyers_business_channel_active_index');
            $table->index(['business_id', 'area']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyers');
    }
};
