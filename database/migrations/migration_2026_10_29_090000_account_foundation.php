<?php

use Spark\Database\Schema\Blueprint;
use Spark\Facades\Schema;

return new class {
    public function up(): void
    {
        Schema::create('policy_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('policy', 40);
            $table->string('version', 40);
            $table->string('source', 40);
            $table->timestamp('accepted_at');
            $table->unique(['user_id', 'policy', 'version']);
        });

        Schema::create('account_action_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 40);
            $table->string('token_hash', 64)->unique();
            $table->string('session_hash', 64)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at');
            $table->index(['user_id', 'purpose']);
        });

        Schema::create('staff_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30);
            $table->text('mfa_secret');
            $table->bigInteger('last_counter')->default(-1);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique('user_id');
        });

        Schema::create('staff_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at');
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->setNullOnDelete();
            $table->string('actor_type', 20);
            $table->string('action', 100)->index();
            $table->json('context');
            $table->timestamp('created_at')->index();
        });

        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->setNullOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->setNullOnDelete();
            $table->foreignId('video_id')->nullable()->constrained()->setNullOnDelete();
            $table->foreignId('reported_user_id')->nullable()->constrained('users')->setNullOnDelete();
            $table->foreignId('audio_id')->nullable()->constrained('audios')->setNullOnDelete();
            $table->foreignId('comment_id')->nullable()->constrained()->setNullOnDelete();
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->string('reason');
            $table->text('details')->nullable();
            $table->text('evidence'); // Encrypted snapshot, accessible only to reviewers.
            $table->string('status', 20)->default('open');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['status', 'id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('moderation_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->setNullOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->setNullOnDelete();
            $table->string('action', 20);
            $table->text('reason');
            $table->boolean('active')->default(true);
            $table->timestamp('created_at');
            $table->index(['subject_user_id', 'id']);
        });

        Schema::create('moderation_restrictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('decision_id')->constrained('moderation_decisions')->cascadeOnDelete();
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->unique(['decision_id', 'target_type', 'target_id']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('moderation_appeals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('decision_id')->constrained('moderation_decisions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->setNullOnDelete();
            $table->text('explanation');
            $table->string('status', 20)->default('open');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->setNullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamps();
            $table->unique('decision_id');
            $table->index(['status', 'id']);
        });

        Schema::create('privacy_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->setNullOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20)->default('pending');
            $table->string('active_key', 80)->nullable()->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_code', 60)->nullable();
            $table->text('manifest')->nullable(); // Encrypted cleanup paths survive retries.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['privacy_requests', 'moderation_appeals', 'moderation_restrictions', 'moderation_decisions', 'reports', 'audit_events', 'staff_sessions', 'staff_accounts', 'account_action_tokens', 'policy_acceptances'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
