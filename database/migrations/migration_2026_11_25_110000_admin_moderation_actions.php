<?php

use Spark\Database\Schema\Blueprint;
use Spark\Facades\{DB, Schema};

return new class {
    public function up(): void
    {
        if (Schema::hasColumn('moderation_decisions', 'target_type')) {
            return;
        }
        foreach (['users', 'audios', 'comments', 'messages'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->unsignedInteger('moderation_version')->default(1));
        }
        foreach (['users', 'videos'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->timestamp('admin_deleted_at')->nullable());
        }
        Schema::table('audios', fn (Blueprint $table) => $table->boolean('reuse_blocked')->default(false));
        Schema::table('moderation_decisions', function (Blueprint $table): void {
            $table->string('target_type', 30)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('reason_code', 30)->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->dateTime('expires_at')->nullable()->index();
            $table->index(['target_type', 'target_id']);
        });
        foreach (DB::table('moderation_decisions')->select(['id', 'report_id'])->get() as $decision) {
            $report = DB::table('reports')->where('id', $decision->report_id)->first();
            if ($report) {
                DB::table('moderation_decisions')->where('id', $decision->id)->update([
                    'target_type' => $report->target_type, 'target_id' => $report->target_id,
                ]);
            }
        }
        foreach (DB::table('reports')->where('status', 'resolved')->get() as $report) {
            $last = DB::table('moderation_decisions')->where('report_id', $report->id)->orderBy('id', 'desc')->first();
            if ($last?->action === 'dismiss') {
                DB::table('reports')->where('id', $report->id)->update(['status' => 'dismissed']);
            }
        }
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('affected_user_id')->nullable()->index();
            $table->string('target_type', 30)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->unsignedBigInteger('report_id')->nullable()->index();
            $table->index(['target_type', 'target_id']);
            $table->index('actor_id');
        });
        foreach (DB::table('audit_events')->select(['id', 'context'])->get() as $event) {
            $context = json_decode($event->context, true) ?: [];
            DB::table('audit_events')->where('id', $event->id)->update([
                'affected_user_id' => $context['subject_user_id'] ?? $context['user_id'] ?? null,
                'target_type' => $context['target_type'] ?? null,
                'target_id' => $context['target_id'] ?? null,
                'report_id' => $context['report_id'] ?? null,
            ]);
        }
        DB::table('staff_accounts')->where('role', 'admin')->update(['role' => 'super_admin', 'version' => DB::raw('version + 1')]);
        DB::table('staff_invitations')->where('role', 'admin')->update(['role' => 'super_admin']);
        DB::table('staff_sessions')->where('id', '>', 0)->delete();
    }

    public function down(): void
    {
        throw new RuntimeException('Moderation decisions must be preserved. Apply a forward migration.');
    }
};
