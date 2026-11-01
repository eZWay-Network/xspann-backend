<?php

use Spark\Database\Schema\Blueprint;
use Spark\Facades\DB;
use Spark\Facades\Schema;

return new class () {
    public function up(): void
    {
        Schema::table('staff_accounts', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
        });

        Schema::table('staff_sessions', function (Blueprint $table): void {
            // Null marks pre-browser grants. Browser authentication never accepts them.
            // The coordinated Step 2 cutover deletes those grants when its routes move.
            $table->unsignedInteger('membership_version')->nullable();
            $table->timestamp('authenticated_at')->nullable();
            $table->timestamp('mfa_at')->nullable();
            $table->timestamp('mfa_expires_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('absolute_expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('device_label', 60)->nullable();
            $table->index(['user_id', 'revoked_at', 'expires_at'], 'staff_sessions_current');
        });

        Schema::create('staff_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inviter_id')->nullable()->constrained('users')->setNullOnDelete();
            $table->string('role', 30);
            $table->unsignedInteger('membership_version');
            $table->unsignedInteger('inviter_version');
            $table->string('token_hash', 64)->unique();
            $table->string('active_key', 80)->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('timezone', 80)->default('UTC');
            $table->string('locale', 10)->default('en');
            $table->unsignedInteger('page_size')->default(20);
            $table->boolean('attention_enabled')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        // Browser session rows are credentials, never downgrade them to legacy grants.
        DB::table('staff_sessions')->whereNotNull('membership_version')->delete();
        Schema::dropIfExists('staff_preferences');
        Schema::dropIfExists('staff_invitations');
        Schema::table('staff_sessions', function (Blueprint $table): void {
            $table->dropIndex('staff_sessions_current');
            $table->dropColumn(['membership_version', 'authenticated_at', 'mfa_at', 'mfa_expires_at', 'last_activity_at', 'absolute_expires_at', 'revoked_at', 'device_label']);
        });
        Schema::table('staff_accounts', fn(Blueprint $table) => $table->dropColumn('version'));
    }
};
