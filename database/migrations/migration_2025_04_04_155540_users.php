<?php

use Spark\Database\Schema\Blueprint;
use Spark\Database\Schema\Schema;

return new class {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('avatar')->nullable();
            $table->text('bio')->nullable();
            $table->string('status')->default('active')->index();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('auth_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->required();
            $table->string('provider_id')->required();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['provider', 'provider_id']);
            $table->unique(['user_id', 'provider']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('jwt_access_tokens', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expire_at')->nullable()->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jwt_access_tokens');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('auth_identities');
        Schema::dropIfExists('users');
    }
};
