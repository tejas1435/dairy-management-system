<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The application audit log (MASTER_SPEC section 59).
 *
 * Append-only: there is no `updated_at`, no update path and no delete path. An
 * audit trail that can be edited is not an audit trail.
 *
 * `auditable_type` stores a stable morph alias, never a PHP class name, so an
 * entry written today is still readable after the class is renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            /*
             * Nullable because a record may be written by a console command or
             * seeder with no signed-in user, and because deleting a user must
             * not delete the history of what they did.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action', 40);
            $table->string('auditable_type', 60);
            $table->unsignedBigInteger('auditable_id');

            /*
             * A human-readable label captured at the time of the change, so the
             * viewer can still say which partner or account was affected after
             * the record is renamed or the row is gone.
             */
            $table->string('subject')->nullable();

            /*
             * Only the fields that actually changed, with sensitive names
             * redacted centrally before they reach here. Never the whole
             * request payload.
             */
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id'], 'audit_logs_auditable_index');
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
