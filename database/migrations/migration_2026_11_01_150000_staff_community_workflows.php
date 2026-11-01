<?php

use Spark\Database\Schema\Blueprint;
use Spark\Facades\{DB, Schema};

return new class () {
    public function up(): void
    {
        if (!Schema::hasColumn('reports', 'source')) {
            Schema::table('reports', function (Blueprint $table): void {
                $table->string('source', 20)->default('customer');
            });
        }

        if (!Schema::hasColumn('reports', 'initiator_id')) {
            if (db()->getDriver() === 'sqlite') {
                $reports = db()->wrapTable('reports');
                $users = db()->wrapTable('users');
                db()->statement("ALTER TABLE $reports ADD COLUMN initiator_id INTEGER REFERENCES $users(id) ON DELETE SET NULL");
            } else {
                Schema::table('reports', function (Blueprint $table): void {
                    $table->foreignId('initiator_id')->nullable()->constrained('users')->setNullOnDelete();
                });
            }
        }

        if (!Schema::hasTable('report_workflows')) {
            Schema::create('report_workflows', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('report_id')->constrained()->cascadeOnDelete();
                $table->unique('report_id');
                $table->foreignId('assignee_id')->nullable()->constrained('users')->setNullOnDelete();
                $table->string('priority', 20)->default('normal');
                $table->string('tag', 30)->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('report_notes')) {
            Schema::create('report_notes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('report_id')->constrained()->cascadeOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->setNullOnDelete();
                $table->text('body');
                $table->timestamp('created_at');
            });
        }

        if (!Schema::hasTable('staff_operations')) {
            Schema::create('staff_operations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('actor_id')->nullable()->constrained('users')->setNullOnDelete();
                $table->unsignedInteger('membership_version');
                $table->string('type', 60);
                $table->unsignedBigInteger('target_id');
                $table->string('environment', 10);
                $table->string('idempotency_hash', 64);
                $table->string('request_hash', 64);
                $table->string('status', 20)->default('queued');
                $table->string('result_code', 60)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('expires_at');
                $table->timestamps();
                $table->unique(['actor_id', 'type', 'idempotency_hash'], 'staff_operation_identity');
                $table->index(['status', 'created_at']);
            });
        }

        if (!Schema::hasTable('staff_worker_heartbeats')) {
            Schema::create('staff_worker_heartbeats', function (Blueprint $table): void {
                $table->id();
                $table->string('worker', 60)->unique();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('succeeded_at')->nullable();
                $table->string('failure_code', 60)->nullable();
            });
        }

        // Cut over together with removal of the legacy staff API routes.
        DB::table('staff_sessions')->whereNull('membership_version')->delete();
        DB::table('account_action_tokens')->where('purpose', 'staff')->delete();
    }

    public function down(): void
    {
        foreach (['staff_worker_heartbeats', 'staff_operations', 'report_notes', 'report_workflows'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('reports', function (Blueprint $table): void {
            if (db()->getDriver() !== 'sqlite') {
                $table->dropForeign(['initiator_id']);
            }
            $table->dropColumn(['source', 'initiator_id']);
        });
    }
};
