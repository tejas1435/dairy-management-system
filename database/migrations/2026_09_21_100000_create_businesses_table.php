<?php

use App\Enums\DateFormat;
use App\Enums\Locale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The business is the root of the data model. V1 holds exactly one row; the
 * table exists so multi-business support never requires a redesign.
 *
 * Business-level settings live here as real columns rather than in a generic
 * key/value store: the specification names them, they are few, and typed
 * columns can be validated and indexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('currency', 3)->default('INR');
            $table->string('timezone', 64)->default('Asia/Kolkata');
            $table->string('date_format', 20)->default(DateFormat::default()->value);
            $table->string('default_locale', 5)->default(Locale::default()->value);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
