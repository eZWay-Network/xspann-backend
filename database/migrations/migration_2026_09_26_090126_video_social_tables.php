<?php

use Spark\Database\Schema\Blueprint;
use Spark\Database\Schema\Schema;

return new class {
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('storage_path');
            $table->string('thumbnail_url')->nullable();
            $table->text('caption')->nullable();
            $table->string('sound_name')->nullable();
            $table->string('sound_artist')->nullable();

            $table->string('location_name')->nullable();
            $table->string('visibility')->default('public');
            $table->boolean('high_quality_upload')->default(true);
            $table->decimal('trim_start', 8, 2)->nullable();
            $table->decimal('trim_end', 8, 2)->nullable();
            $table->decimal('cover_time', 8, 2)->nullable();
            $table->string('crop_mode')->default('fit');
            $table->string('text_overlay')->nullable();
            $table->boolean('original_audio_muted')->default(false);
            $table->string('sound_provider')->nullable();
            $table->string('sound_external_id')->nullable();
            $table->string('sound_preview_url')->nullable();

            $table->json('cut_points')->nullable();
            $table->json('filter_settings')->nullable();
            $table->json('effect_settings')->nullable();

            $table->unsignedInteger('duration')->nullable();
            $table->string('status')->default('processing')->index();
            $table->unsignedBigInteger('views_count')->default(0);
            $table->unsignedBigInteger('likes_count')->default(0);
            $table->unsignedBigInteger('comments_count')->default(0);
            $table->unsignedBigInteger('saves_count')->default(0);
            $table->unsignedBigInteger('shares_count')->default(0);

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('pinned_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'pinned_at']);
        });

        Schema::create('likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'video_id']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['video_id', 'created_at']);
        });

        Schema::create('comments_reacts', function (Blueprint $table) {
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reaction_type')->default('like')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'comment_id']);
            $table->index(['comment_id', 'reaction_type']);
        });

        Schema::create('saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'video_id']);
        });

        Schema::create('shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->setNullOnDelete();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->string('channel')->default('copy_link');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['video_id', 'created_at']);
        });

        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('following_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['follower_id', 'following_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follows');
        Schema::dropIfExists('shares');
        Schema::dropIfExists('saves');
        Schema::dropIfExists('comments_reacts');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('likes');
        Schema::dropIfExists('videos');
    }
};