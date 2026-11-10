<?php

use Spark\Database\Schema\Blueprint;
use Spark\Facades\{DB, Schema};

return new class {
    public function up(): void
    {
        Schema::create('promotion_platforms', function (Blueprint $table): void {
            $table->id();
            $table->string('environment', 10);
            $table->string('surface', 60);
            $table->string('platform', 10);
            $table->boolean('enabled');
            $table->timestamp('changed_at');
            $table->unique(['environment', 'surface', 'platform'], 'promotion_platform_switch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_platforms');
    }
};
