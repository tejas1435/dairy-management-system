<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a payment happened: cash, UPI, bank transfer, cheque, other.
 *
 * Records only. Nothing here processes a payment, and the application connects
 * to no gateway or bank (MASTER_SPEC sections 26 and 46).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            /*
             * The stable machine identifier. Business logic and seeders match on
             * `code`, never on `name`, so an administrator can relabel "UPI" in
             * any language without breaking anything.
             */
            $table->string('code', 40)->unique();

            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
