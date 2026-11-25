<?php

use Spark\Database\Schema\Blueprint;
use Spark\Facades\{DB, Schema};

return new class {
    public function up(): void
    {
        if (Schema::hasColumn('staff_sessions', 'confirmed_at')) {
            return;
        }

        $table = db()->wrapTable('staff_accounts');
        DB::statement("ALTER TABLE $table MODIFY mfa_secret TEXT NULL");

        Schema::table('staff_sessions', fn(Blueprint $table) => $table->timestamp('confirmed_at')->nullable());
        DB::table('staff_sessions')->where('id', '>', 0)->delete();
    }

    public function down(): void
    {
        throw new RuntimeException('Password-only staff accounts cannot be downgraded to mandatory MFA. Apply a forward migration.');
    }
};
