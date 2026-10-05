<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where milk is sold (MASTER_SPEC section 12).
 *
 * Channels are data, not code. Mandali, Vendor and Direct Customer are seeded
 * as system channels because later phases give them specialised workflows, but
 * an administrator can add a hotel, a sweet shop or a bulk buyer at any time and
 * those use the generic sale entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            /*
             * The stable machine identifier. Later phases branch on `slug` to
             * decide which specialised workflow applies, so a system channel's
             * slug is immutable while its display name is freely editable.
             */
            $table->string('slug', 40);

            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_channels');
    }
};
