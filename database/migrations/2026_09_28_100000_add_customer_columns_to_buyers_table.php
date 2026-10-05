<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two buyer-level fields a direct customer needs (MASTER_SPEC section 16).
 *
 * A direct customer is **a buyer whose sales channel is `direct_customer`**, not a
 * separate identity. Name, mobile, email, address, area, payment cycle and active
 * state already live on `buyers` and are not duplicated here; only the two fields
 * that had no reader before Phase 4 are added.
 *
 * Both are nullable, because they are meaningless for a Mandali or a vendor and
 * optional even for a customer:
 *
 *   - `delivery_note` is the standing instruction the delivery person needs —
 *     "gate is locked, leave with the neighbour". It is displayed on the daily
 *     entry grid and never interpreted.
 *   - `start_date` is the first date the customer should appear as a delivery row.
 *     Absent means "from the beginning", which is how existing customers behave.
 *
 * Both were planned in Phase 0 and deliberately not built until now; see the note
 * under `buyers` in docs/DATABASE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->text('delivery_note')->nullable()->after('area');
            $table->date('start_date')->nullable()->after('payment_cycle');

            /*
             * Delivery eligibility for a date filters on start_date alongside
             * is_active, so the two are indexed together with the business.
             */
            $table->index(['business_id', 'is_active', 'start_date'], 'buyers_delivery_eligibility_index');
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->dropIndex('buyers_delivery_eligibility_index');
            $table->dropColumn(['delivery_note', 'start_date']);
        });
    }
};
