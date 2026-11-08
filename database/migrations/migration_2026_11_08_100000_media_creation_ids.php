<?php

use Spark\Database\Schema\Blueprint;
use Spark\Facades\Schema;

return new class {
    public function up(): void
    {
        foreach (['videos', 'audios'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->string('creation_id', 36)->nullable();
                $table->unique(['user_id', 'creation_id'], $name . '_owner_creation');
            });
        }
    }

    public function down(): void
    {
        foreach (['videos', 'audios'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropUnique($name . '_owner_creation');
                $table->dropColumn('creation_id');
            });
        }
    }
};
