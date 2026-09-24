<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->string('location_name')->nullable()->after('sound_artist');
            $table->string('visibility')->default('public')->after('location_name');
            $table->boolean('high_quality_upload')->default(true)->after('visibility');
            $table->timestamp('scheduled_at')->nullable()->after('high_quality_upload');
            $table->decimal('trim_start', 8, 2)->nullable()->after('scheduled_at');
            $table->decimal('trim_end', 8, 2)->nullable()->after('trim_start');
            $table->decimal('cover_time', 8, 2)->nullable()->after('trim_end');
            $table->string('crop_mode')->default('fit')->after('cover_time');
            $table->string('text_overlay')->nullable()->after('crop_mode');
            $table->boolean('original_audio_muted')->default(false)->after('text_overlay');
            $table->string('sound_provider')->nullable()->after('original_audio_muted');
            $table->string('sound_external_id')->nullable()->after('sound_provider');
            $table->string('sound_preview_url')->nullable()->after('sound_external_id');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn([
                'location_name',
                'visibility',
                'high_quality_upload',
                'scheduled_at',
                'trim_start',
                'trim_end',
                'cover_time',
                'crop_mode',
                'text_overlay',
                'original_audio_muted',
                'sound_provider',
                'sound_external_id',
                'sound_preview_url',
            ]);
        });
    }
};
