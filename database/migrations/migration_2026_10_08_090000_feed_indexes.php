<?php

use Spark\Database\Schema\Blueprint;
use Spark\Database\Schema\Schema;

return new class {
    public function up(): void
    {
        Schema::table('video_views', function (Blueprint $table): void {
            $table->index(['user_id', 'id', 'video_id'], 'idx_views_user_history');
            $table->index(['ip_hash', 'user_agent_hash', 'id', 'video_id'], 'idx_views_guest_history');
        });

        Schema::table('videos', function (Blueprint $table): void {
            $table->index(['status', 'id'], 'idx_videos_feed');
        });
    }

    public function down(): void
    {
        Schema::table('video_views', function (Blueprint $table): void {
            $table->dropIndex('idx_views_user_history');
            $table->dropIndex('idx_views_guest_history');
        });

        Schema::table('videos', function (Blueprint $table): void {
            $table->dropIndex('idx_videos_feed');
        });
    }
};
