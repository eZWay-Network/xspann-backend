<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\AccountSecurity;
use Spark\Facades\{DB, Session};

trait StaffBrowser
{
    protected function browserAs(User $user): static
    {
        $this->browserLogin($user);

        return $this;
    }

    protected function browserEvidence(string $casePath)
    {
        return $this->get($casePath . '/evidence', ['Accept' => 'application/json']);
    }

    protected function assertBrowserRejected($response): void
    {
        $response->assertStatus(303);
        $this->assertTrue(count(session()->getFlash('errors', [])) > 0, 'A rejected action must return form errors.');
    }

    protected function browserHeaders(): array
    {
        $manifest = dirname(__DIR__, 2) . '/public/build/.vite/manifest.json';

        return ['X-Inertia' => 'true', 'Accept' => 'text/html', 'X-Inertia-Version' => is_file($manifest) ? hash_file('xxh128', $manifest) : ''];
    }

    protected function browserLogin(User $user, string $role = 'super_admin'): void
    {
        $role = $role === 'admin' ? 'super_admin' : $role;
        $this->app->mergeConfig(['app' => ['url' => 'http://localhost']]);
        Session::flush();
        $this->flushHeaders();
        $member = DB::table('staff_accounts')->where('user_id', $user->id)->first();
        if (!$member) {
            if (in_array($role, \App\Services\Staff\Permissions::roles(), true)) {
                AccountSecurity::enrollStaff($user, $role);
            } else {
                DB::table('staff_accounts')->insert(['user_id' => $user->id, 'role' => $role, 'enabled' => true, 'version' => 1, 'mfa_secret' => null, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $this->browserPage('/login')->assertOk();
        $this->browserWrite('/login', ['email' => $user->email, 'password' => 'password123'])->assertStatus(303);
    }

    protected function browserPage(string $path)
    {
        return $this->get($path, $this->browserHeaders());
    }

    protected function browserWrite(string $path, array $data = [], string|array $method = 'POST', ?string $referer = null, array $headers = [])
    {
        if (is_array($method)) {
            $headers = $method;
            $method = 'POST';
        }

        if (preg_match('~^/moderation/(reports|appeals)/(\d+)/decision$~', $path, $match)) {
            $data += ['reason_code' => 'other'];
            if ($match[1] === 'reports') {
                $report = DB::table('reports')->where('id', (int) $match[2])->first();
                if ($report) {
                    $type = in_array($data['action'] ?? '', ['warn', 'suspend', 'ban', 'revoke_sessions'], true) ? 'user' : $report->target_type;
                    $id = $type === 'user' ? $report->subject_user_id : $report->target_id;
                    if (($data['action'] ?? '') === 'restore') {
                        $decision = DB::table('moderation_decisions')->where('report_id', $report->id)->where('active', true)->orderBy('id', 'desc')->first();
                        $data += ['decision_id' => $decision?->id];
                        $type = $decision?->target_type ?? $type;
                        $id = $decision?->target_id ?? $id;
                    }
                    $target = DB::table(\App\Services\Moderation::TABLES[$type])->where('id', $id)->first();
                    $column = \App\Services\Moderation::versionColumn($type);
                    $data += ['target_version' => (int) ($target?->$column ?? 1)];
                }
            }
        }

        return $this->request($method, $path, $data, [...$this->browserHeaders(), 'X-CSRF-TOKEN' => session('csrf_token', ''),
            'Referer' => (string) url($referer ?? '/'), ...$headers]);
    }
}
