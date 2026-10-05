<?php

use App\Enums\Locale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends Laravel's users table for this application.
 *
 * No tenant_id, organisation or subscription columns: this is a single-business
 * application, and business_id exists only so users are attributable to the
 * business that owns them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('business_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
            $table->string('locale', 5)->default(Locale::default()->value)->after('password');
            $table->boolean('is_active')->default(true)->after('locale');
            $table->timestamp('last_login_at')->nullable()->after('is_active');

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->dropIndex(['is_active']);
            $table->dropColumn(['business_id', 'locale', 'is_active', 'last_login_at']);
        });
    }
};
