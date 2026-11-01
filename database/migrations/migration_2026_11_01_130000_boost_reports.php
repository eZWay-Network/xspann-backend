<?php

use Spark\Database\Schema\{Blueprint, Wrapper};
use Spark\Facades\Schema;

return new class {
    public function up(): void
    {
        if (Schema::hasColumn('reports', 'boost_id')) {
            return;
        }

        if (db()->getDriver() === 'sqlite') {
            $wrapper = new Wrapper('sqlite', db()->getTablePrefix());
            $reports = $wrapper->wrapTable('reports');
            $boosts = $wrapper->wrapTable('promotion_boosts');
            db()->statement("ALTER TABLE $reports ADD COLUMN boost_id INTEGER REFERENCES $boosts(id) ON DELETE SET NULL");
        } else {
            Schema::table('reports', function (Blueprint $table): void {
                $table->foreignId('boost_id')->nullable()->constrained('promotion_boosts')->setNullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (query('reports')->where('target_type', 'boost')->exists()) {
            throw new RuntimeException('Retain boost report evidence and its target linkage; use a forward correction instead.');
        }

        Schema::table('reports', function (Blueprint $table): void {
            if (db()->getDriver() !== 'sqlite') {
                $table->dropForeign(['boost_id']);
            }
            $table->dropColumn('boost_id');
        });
    }
};
