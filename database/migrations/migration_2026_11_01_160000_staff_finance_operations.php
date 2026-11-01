<?php

use Spark\Database\Schema\Blueprint;
use Spark\Facades\Schema;

return new class {
    public function up(): void
    {
        if (!Schema::hasTable('staff_settings')) {
            Schema::create('staff_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key', 60)->unique();
                $table->string('value', 255);
                $table->unsignedInteger('version')->default(1);
                $table->foreignId('updated_by')->nullable()->constrained('users')->setNullOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('staff_exports')) {
            Schema::create('staff_exports', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('actor_id')->nullable()->constrained('users')->setNullOnDelete();
                $table->unsignedInteger('membership_version');
                $table->string('type', 40);
                $table->string('environment', 10);
                $table->text('filters');
                $table->unsignedInteger('schema_version')->default(1);
                $table->string('idempotency_hash', 64);
                $table->unique(['actor_id', 'idempotency_hash'], 'staff_export_identity');
                $table->string('status', 20)->default('queued');
                $table->unsignedInteger('row_count')->nullable();
                $table->string('error_code', 60)->nullable();
                $table->timestamp('expires_at');
                $table->timestamps();
                $table->index(['actor_id', 'created_at']);
            });
        }

        if (!Schema::hasColumn('staff_worker_heartbeats', 'duration_ms')) {
            Schema::table('staff_worker_heartbeats', fn (Blueprint $table) => $table->unsignedInteger('duration_ms')->nullable());
        }

        if (!Schema::hasColumn('artist_genres', 'archived')) {
            Schema::table('artist_genres', function (Blueprint $table): void {
                $table->boolean('archived')->default(false);
                $table->unsignedInteger('position')->default(0);
                $table->unsignedInteger('version')->default(1);
            });
        }
    }

    public function down(): void
    {
        Schema::table('staff_worker_heartbeats', fn (Blueprint $table) => $table->dropColumn('duration_ms'));
        Schema::dropIfExists('staff_exports');
        Schema::dropIfExists('staff_settings');
        Schema::table('artist_genres', fn (Blueprint $table) => $table->dropColumn(['archived', 'position', 'version']));
    }
};
