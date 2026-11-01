<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\{AccountSecurity, Totp};
use Spark\Facades\{DB, Hash, Session};

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

    protected function browserLogin(User $user, string $role = 'admin'): void
    {
        $this->app->mergeConfig(['app' => ['url' => 'http://localhost']]);
        Session::flush();
        $this->flushHeaders();
        $member = DB::table('staff_accounts')->where('user_id', $user->id)->first();
        if (!$member) {
            $secret = Totp::secret();
            AccountSecurity::enrollStaff($user, $role, $secret, Totp::code($secret, intdiv(time(), 30) - 1));
        } else {
            $secret = Hash::decrypt($member->mfa_secret);
            // A fresh synthetic browser is allowed to use the current test time step.
            DB::table('staff_accounts')->where('id', $member->id)->update(['last_counter' => intdiv(time(), 30) - 1]);
        }
        $this->browserPage('/login')->assertOk();
        $this->browserWrite('/login', ['email' => $user->email, 'password' => 'password123'])->assertStatus(303);
        $this->browserWrite('/challenge', ['mfa_code' => Totp::code($secret, intdiv(time(), 30))])->assertStatus(303);
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

        return $this->request($method, $path, $data, [...$this->browserHeaders(), 'X-CSRF-TOKEN' => session('csrf_token', ''),
            'Referer' => (string) url($referer ?? '/'), ...$headers]);
    }
}
