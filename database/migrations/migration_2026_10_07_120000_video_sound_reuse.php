<?php

use Spark\Database\Schema\Blueprint;
use Spark\Database\Schema\Schema;

return new class {
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->boolean('reuse_content')->default(true);
            $table->index(['sound_provider', 'sound_external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropIndex(['sound_provider', 'sound_external_id']);
            $table->dropColumn('reuse_content');
        });
    }
};
