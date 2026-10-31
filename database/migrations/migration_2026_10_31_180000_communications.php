<?php

use Spark\Database\Schema\Blueprint;
use Spark\Facades\{DB, Schema};

return new class {
    public function up(): void
    {
        // A database mutex keeps consent transitions and audience snapshots atomic on all drivers.
        Schema::create('communication_locks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('revision')->default(0);
        });
        DB::table('communication_locks')->insert(['id' => 1, 'revision' => 0]);

        Schema::create('communication_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique('user_id');
            $table->boolean('allow_direct_messages')->default(true);
            $table->boolean('artist_updates_enabled')->default(true);
            $table->boolean('message_alerts_enabled')->default(true);
            $table->boolean('mute_new_conversations')->default(false);
            $table->unsignedInteger('updates_revision')->default(1);
        });

        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('low_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('high_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('created_at');
            $table->unique(['low_user_id', 'high_user_id']);
            $table->index(['created_by', 'created_at']);
            $table->index(['created_at', 'id']);
        });

        Schema::create('conversation_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->boolean('muted')->default(false);
            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'conversation_id']);
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('client_message_id', 36);
            $table->text('body');
            $table->string('request_hash', 64);
            $table->string('moderation_status', 20)->default('approved');
            $table->timestamp('created_at');
            $table->unique(['user_id', 'client_message_id']);
            $table->index(['conversation_id', 'id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('artist_update_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('subscribed')->default(false);
            $table->string('consent_version', 80)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('updated_at');
            $table->unique(['artist_id', 'user_id']);
        });

        Schema::create('communication_consent_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artist_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('purpose', 40);
            $table->string('channel', 20)->default('in_app');
            $table->string('source', 20)->default('authenticated_api');
            $table->boolean('enabled');
            $table->string('consent_version', 80)->nullable();
            $table->unsignedInteger('revision');
            $table->timestamp('created_at');
        });

        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Preserve the reference after deletion so queued/delivered content becomes unavailable.
            $table->unsignedBigInteger('video_id')->nullable();
            $table->text('title');
            $table->text('body');
            $table->string('status', 20)->default('draft');
            $table->string('moderation_status', 20)->default('approved');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('recipient_count')->default(0);
            $table->foreignId('paid_period_id')->nullable()->constrained('billing_paid_periods')->setNullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->index(['paid_period_id', 'status']);
            $table->index(['user_id', 'created_at', 'id']);
            $table->index(['status', 'updated_at', 'id']);
        });

        Schema::create('announcement_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('consent_revision');
            $table->unsignedInteger('updates_revision');
            $table->string('status', 20)->default('pending');
            $table->foreignId('notification_id')->nullable()->constrained()->setNullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->unique(['announcement_id', 'user_id']);
            $table->index(['user_id', 'status', 'id']);
        });

        Schema::create('communication_idempotency', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 40);
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->text('result');
            $table->timestamp('created_at');
            $table->unique(['user_id', 'operation', 'key_hash']);
        });

        Schema::create('realtime_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->foreign('token_hash')->references('token_hash')->on('jwt_access_tokens')->cascadeOnDelete();
            $table->string('channel_key', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('retired_at')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('realtime_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->unsignedBigInteger('resource_id');
            $table->unsignedBigInteger('version');
            $table->timestamp('created_at');
            $table->timestamp('available_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->index(['completed_at', 'available_at', 'id']);
        });

        // Earlier withQueue list registrations landed on default despite onQueue().
        // Move only the known recurring database jobs; preserve payload/state and unrelated media jobs.
        $queues = [
            \App\Jobs\PublishReleases::class => 'releases',
            \App\Jobs\BillingMaintenance::class => 'billing',
            \App\Jobs\FoundationMaintenance::class => 'privacy',
        ];
        foreach (DB::table('jobs')->where('queue', 'default')->whereNotNull('repeat')->get() as $job) {
            $callback = json_decode($job->payload, true)['callback'] ?? null;
            if (is_string($callback) && isset($queues[$callback])) {
                DB::table('jobs')->where('id', $job->id)->where('queue', 'default')->update(['queue' => $queues[$callback]]);
            }
        }

        foreach (['message_id' => 'messages', 'announcement_id' => 'announcements'] as $column => $target) {
            if (db()->getDriver() === \Spark\Database\DB::DRIVER_SQLITE) {
                db()->statement("ALTER TABLE reports ADD COLUMN $column INTEGER REFERENCES $target(id) ON DELETE SET NULL");
            } else {
                Schema::table('reports', function (Blueprint $table) use ($column, $target): void {
                    $table->foreignId($column)->nullable()->constrained($target)->setNullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['message_id', 'announcement_id'] as $column) {
            Schema::table('reports', function (Blueprint $table) use ($column): void {
                if (db()->getDriver() !== \Spark\Database\DB::DRIVER_SQLITE) {
                    $table->dropForeign([$column]);
                }
                $table->dropColumn($column);
            });
        }

        foreach (['realtime_outbox', 'realtime_sessions', 'communication_idempotency', 'announcement_deliveries', 'announcements', 'communication_consent_history', 'artist_update_subscriptions', 'messages', 'conversation_participants', 'conversations', 'communication_preferences', 'communication_locks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
