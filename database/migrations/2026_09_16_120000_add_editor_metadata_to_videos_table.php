<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->json('cut_points')->nullable()->after('trim_end');
            $table->json('filter_settings')->nullable()->after('original_audio_muted');
            $table->json('effect_settings')->nullable()->after('filter_settings');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn([
                'cut_points',
                'filter_settings',
                'effect_settings',
            ]);
        });
    }
};
