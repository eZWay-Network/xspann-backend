<?php

namespace App\Http\Middlewares;

use App\Services\AccountSecurity;
use Spark\Facades\DB;
use Spark\Http\Request;

class StaffMiddleware extends AuthMiddleware
{
    public function handle(Request $request, \Closure $next, ...$guards): mixed
    {
        return parent::handle($request, function (Request $request) use ($next) {
            abort_unless(
                DB::table('staff_accounts')->where('user_id', $request->user('id'))
                    ->whereIn('role', ['admin', 'moderator'])->where('enabled', true)->exists(),
                403,
                'Staff access is required.'
            );

            abort_unless(
                DB::table('staff_sessions')->where('user_id', $request->user('id'))
                    ->where('session_hash', AccountSecurity::session())->where('expires_at', '>', now()->toDateTimeString())->exists(),
                403,
                'Recent staff MFA is required.'
            );

            $response = $next($request);

            return $response instanceof \Spark\Http\Response ? $response->setHeader('Cache-Control', 'no-store') : $response;
        }, ...$guards);
    }
}
