<?php

use Spark\Database\Schema\Blueprint;
use Spark\Facades\{DB, Schema};

return new class {
    public function up(): void
    {
        Schema::create('promotion_locks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('revision')->default(0);
        });
        DB::table('promotion_locks')->insert(['id' => 1, 'revision' => 0]);

        Schema::create('promotion_invalidations', function (Blueprint $table): void {
            $table->id();
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->timestamp('created_at');
            $table->index(['target_type', 'target_id', 'id'], 'promotion_invalidation_target');
        });

        Schema::create('promotion_credits', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->setNullOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('billing_paid_periods')->setNullOnDelete();
            $table->unique('period_id');
            $table->string('environment', 10);
            $table->unsignedInteger('amount_cents');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('promotion_boosts', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->setNullOnDelete();
            $table->foreignId('artist_id')->nullable()->constrained('artists')->setNullOnDelete();
            $table->foreignId('video_id')->nullable()->constrained('videos')->setNullOnDelete();
            $table->string('environment', 10);
            $table->unsignedBigInteger('eligibility_revision')->default(0);
            $table->string('offer_id', 80);
            $table->string('surface', 60);
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('duration_seconds');
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->string('rights_version', 80);
            $table->timestamp('rights_confirmed_at');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('settle_after')->nullable();
            $table->string('stop_reason', 80)->nullable();
            $table->boolean('staff_hold')->default(false);
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['environment', 'status', 'id'], 'promotion_boost_work');
        });

        Schema::create('promotion_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('boost_id')->constrained('promotion_boosts')->cascadeOnDelete();
            $table->foreignId('credit_id')->constrained('promotion_credits');
            $table->unsignedInteger('amount_cents');
            $table->string('status', 20)->default('reserved');
            $table->unsignedInteger('returned_cents')->default(0);
            $table->unique(['boost_id', 'credit_id']);
        });

        Schema::create('promotion_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credit_id')->constrained('promotion_credits')->cascadeOnDelete();
            $table->foreignId('boost_id')->nullable()->constrained('promotion_boosts')->setNullOnDelete();
            $table->string('entry_key', 120)->unique();
            $table->string('kind', 30);
            $table->integer('available_delta');
            $table->integer('reserved_delta');
            $table->integer('used_delta');
            $table->timestamp('created_at');
        });

        Schema::create('promotion_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('boost_id')->constrained('promotion_boosts')->cascadeOnDelete();
            $table->string('environment', 10);
            $table->string('surface', 60);
            $table->unsignedInteger('slot');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('released_at')->nullable();
            $table->index(['environment', 'surface', 'slot', 'ends_at'], 'promotion_slot_inventory');
        });

        Schema::create('promotion_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->setNullOnDelete();
            $table->string('environment', 10);
            $table->string('operation', 40);
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->text('result');
            $table->timestamp('created_at');
            $table->unique(['user_id', 'environment', 'operation', 'key_hash'], 'promotion_operation_key');
        });

        Schema::create('promotion_availability', function (Blueprint $table): void {
            $table->id();
            $table->string('environment', 10);
            $table->string('surface', 60);
            $table->boolean('available');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reference', 200);
            $table->text('note');
            $table->foreignId('actor_id')->nullable()->constrained('users')->setNullOnDelete();
            $table->string('actor_type', 20);
            $table->string('request_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['environment', 'surface', 'reference'], 'promotion_health_reference');
            $table->index(['environment', 'surface', 'ends_at'], 'promotion_health_window');
        });

        Schema::create('promotion_delivery', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('boost_id')->constrained('promotion_boosts')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unique(['boost_id', 'starts_at', 'ends_at'], 'promotion_delivery_window');
        });

        Schema::create('promotion_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('boost_id')->constrained('promotion_boosts')->cascadeOnDelete();
            $table->string('event_id', 36)->unique();
            $table->string('kind', 10);
            $table->string('token_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['token_hash', 'kind'], 'promotion_event_token_kind');
        });

        Schema::create('promotion_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('artist_id')->nullable()->constrained('artists')->setNullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->setNullOnDelete();
            $table->string('environment', 10);
            $table->string('month', 7);
            $table->string('network_account', 80);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->setNullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['artist_id', 'environment', 'month'], 'promotion_pilot_month');
        });

        Schema::create('promotion_placements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrollment_id')->nullable()->constrained('promotion_enrollments')->cascadeOnDelete();
            $table->foreignId('boost_id')->nullable()->constrained('promotion_boosts')->cascadeOnDelete();
            $table->unique('boost_id');
            $table->foreignId('artist_id')->nullable()->constrained('artists')->setNullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->setNullOnDelete();
            $table->string('environment', 10);
            $table->unsignedBigInteger('eligibility_revision')->default(0);
            $table->string('type', 20);
            $table->string('month', 7)->nullable();
            $table->string('destination', 80);
            $table->string('status', 30)->default('planned');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('extension_starts_at')->nullable();
            $table->timestamp('extension_ends_at')->nullable();
            $table->string('extension_consent', 20)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['enrollment_id', 'type'], 'promotion_monthly_obligation');
        });

        Schema::create('promotion_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('placement_id')->constrained('promotion_placements')->cascadeOnDelete();
            $table->unique('placement_id');
            $table->string('proof_hash', 64)->unique();
            $table->text('proof');
            $table->string('request_hash', 64);
            $table->timestamp('actual_starts_at');
            $table->timestamp('actual_ends_at');
            $table->text('note');
            $table->foreignId('actor_id')->nullable()->constrained('users')->setNullOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('promotion_ledger')->exists() || DB::table('promotion_enrollments')->exists() || DB::table('promotion_boosts')->exists()) {
            throw new RuntimeException('Retain promotion credit and obligation history; resolve it before rollback.');
        }
        foreach (['promotion_evidence', 'promotion_placements', 'promotion_enrollments', 'promotion_events', 'promotion_delivery', 'promotion_availability', 'promotion_operations', 'promotion_slots', 'promotion_ledger', 'promotion_reservations', 'promotion_boosts', 'promotion_credits', 'promotion_invalidations', 'promotion_locks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
