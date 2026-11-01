<?php

use Spark\Database\Schema\Wrapper;

return new class {
    public function up(): void
    {
        // SQLite and PostgreSQL already support the release API's four-digit years.
        // MySQL TIMESTAMP stops in 2038; preserve UTC wall-clock values and the due index.
        if (db()->getDriver() === 'mysql') {
            $table = (new Wrapper('mysql', db()->getTablePrefix()))->wrapTable('videos');
            db()->statement("ALTER TABLE $table MODIFY COLUMN publish_at DATETIME NULL DEFAULT NULL");
        }
    }

    public function down(): void
    {
        if (db()->getDriver() !== 'mysql') {
            return;
        }

        if (query('videos')->whereNotNull('publish_at')->where(fn($query) => $query
            ->where('publish_at', '<', '1970-01-01 00:00:01')
            ->orWhere('publish_at', '>', '2038-01-19 03:14:07'))->exists()) {
            throw new RuntimeException('Resolve release schedules outside the MySQL TIMESTAMP range before rollback.');
        }

        $table = (new Wrapper('mysql', db()->getTablePrefix()))->wrapTable('videos');
        db()->statement("ALTER TABLE $table MODIFY COLUMN publish_at TIMESTAMP NULL DEFAULT NULL");
    }
};
