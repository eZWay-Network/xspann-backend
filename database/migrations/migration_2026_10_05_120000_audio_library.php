<?php

use Spark\Database\Schema\Blueprint;
use Spark\Database\Schema\Schema;

return new class {
    public function up(): void
    {
        Schema::create('audios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_video_id')->nullable()->constrained('videos')->setNullOnDelete();
            $table->string('title', 120);
            $table->string('storage_path', 2048);
            $table->string('origin', 20)->default('upload');
            $table->string('status', 20)->default('processing');
            $table->unsignedInteger('duration')->nullable();
            $table->timestamps();
            $table->unique('source_video_id');
            $table->index(['status', 'created_at']);
        });

        Schema::create('audio_saves', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audio_id')->constrained('audios')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'audio_id']);
            $table->index(['user_id', 'created_at']);
        });

        // Spark cannot add SQLite foreign keys separately; add this nullable reference inline.
        if (db()->getDriver() === 'sqlite') {
            db()->statement('ALTER TABLE videos ADD COLUMN audio_id INTEGER REFERENCES audios(id) ON DELETE SET NULL');
        } else {
            Schema::table('videos', function (Blueprint $table) {
                $table->foreignId('audio_id')->nullable()->constrained('audios')->setNullOnDelete();
            });
        }

        Schema::table('videos', function (Blueprint $table) {
            $table->string('audio_mode', 10)->default('replace');
            $table->index('audio_id');
        });

        Schema::create('pending_uploads', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('disk', 80);
            $table->string('path', 255);
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropIndex(['audio_id']);
            if (db()->getDriver() !== 'sqlite') {
                $table->dropForeign(['audio_id']);
            }
            $table->dropColumn('audio_id');
            $table->dropColumn('audio_mode');
        });

        Schema::dropIfExists('audio_saves');
        Schema::dropIfExists('audios');
        Schema::dropIfExists('pending_uploads');
    }
};
