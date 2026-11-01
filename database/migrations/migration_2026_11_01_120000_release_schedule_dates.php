<?php

use Spark\Facades\DB;

return new class () {
    public function up(): void
    {
        if (db()->getDriver() === 'mysql') {
            // Keep the UTC values and due index while allowing dates beyond TIMESTAMP's range.
            $table = db()->wrapTable('videos');
            db()->statement("ALTER TABLE $table MODIFY COLUMN publish_at DATETIME NULL DEFAULT NULL");
        }
    }

    public function down(): void
    {
        if (db()->getDriver() !== 'mysql') {
            return;
        }

        if (DB::table('videos')->whereNotNull('publish_at')->where(fn ($query) => $query
            ->where('publish_at', '<', '1970-01-01 00:00:01')
            ->orWhere('publish_at', '>', '2038-01-19 03:14:07'))->exists()) {
            throw new RuntimeException('Release dates exceed the old TIMESTAMP range. Keep the forward migration.');
        }

        $table = db()->wrapTable('videos');
        db()->statement("ALTER TABLE $table MODIFY COLUMN publish_at TIMESTAMP NULL DEFAULT NULL");
    }
};
