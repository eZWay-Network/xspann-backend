<?php

namespace App\Services\Billing;

use App\Models\User;
use Spark\Facades\{Cache, DB};
use function in_array;
use function is_string;

class Billing
{
    public static function table(string $name): \Spark\Database\QueryBuilder
    {
        return DB::table('billing_' . $name)->where('provider', 'stripe')
            ->where('environment', config('billing.environment'));
    }

    public static function locked(int $userId, callable $callback): mixed
    {
        return Cache::lock("billing.account.$userId", 300)->block(10, $callback);
    }

    public static function timestamp(?int $value = null): string
    {
        return gmdate('Y-m-d H:i:s', $value ?? time());
    }

    public static function date(?string $value): ?string
    {
        return $value ? str_replace(' ', 'T', $value) . 'Z' : null;
    }

    public static function access(int $userId): ?object
    {
        return DB::table('billing_paid_periods as p')
            ->join('billing_subscriptions as s', 's.id', '=', 'p.subscription_id')
            ->join('billing_catalog as c', 'c.id', '=', 's.catalog_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->select(['c.plan_code', 'p.ends_at', 'p.id', 's.id as subscription_id'])
            ->where('s.user_id', $userId)->where('s.environment', config('billing.environment'))
            ->where('u.status', 'active')->whereNull('s.deletion_requested_at')
            ->whereNull('p.revoked_at')->where('p.starts_at', '<=', self::timestamp())
            ->where('p.ends_at', '>', self::timestamp())->orderByRaw('p.ends_at DESC, p.id DESC')->first();
    }

    public static function subscription(int $userId): ?object
    {
        return self::table('subscriptions')->where('user_id', $userId)->orderBy('id', 'DESC')->first();
    }

    public static function subscriptionData(int $userId): array
    {
        $subscription = self::subscription($userId);
        $access = self::access($userId);
        $catalog = $subscription ? DB::table('billing_catalog')->where('id', $subscription->catalog_id)->first() : null;

        return [
            'status' => $subscription?->status ?? 'none',
            'plan' => $catalog?->plan_code ?? 'free',
            'effective_plan' => $access?->plan_code ?? 'free',
            'paid_through' => self::date($access?->ends_at),
            'cancel_at_period_end' => (bool) ($subscription?->cancel_at_period_end ?? false),
            'period_end' => self::date($subscription?->period_end),
            'renewal_pending' => (bool) $subscription?->renewal_operation,
        ];
    }

    public static function assertEnvironment(array $object): void
    {
        abort_unless(($object['livemode'] ?? null) === (config('billing.environment') === 'live'), 409, 'Provider environment mismatch.');
    }

    public static function assertAccount(PaymentProvider $provider): void
    {
        $expected = config('billing.stripe_account');
        abort_unless(
            is_string($expected) && preg_match('/^acct_[a-zA-Z0-9]+$/D', $expected),
            503,
            'Configure the billing account.'
        );

        abort_unless(
            ($provider->request('GET', '/account')['id'] ?? null) === $expected,
            503,
            'Billing account mismatch.'
        );
    }

    public static function returnUrl(string $field): string
    {
        $url = config("billing.$field");
        $scheme = is_string($url) ? parse_url($url, PHP_URL_SCHEME) : null;

        abort_unless(
            is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
            && in_array($scheme, config('billing.environment') === 'live' ? ['https'] : ['http', 'https'], true)
            && !parse_url($url, PHP_URL_USER) && !parse_url($url, PHP_URL_FRAGMENT),
            503,
            'Configure fixed billing return URLs.'
        );

        return $url;
    }

    public static function hostedUrl(mixed $url, string $host): ?string
    {
        return is_string($url) && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_HOST) === $host && !parse_url($url, PHP_URL_USER)
            ? $url : null;
    }

    public static function activeUser(int $id): User
    {
        $user = self::owner($id);
        abort_unless(
            $user->status === 'active',
            403,
            'Account is not eligible for new billing activity.'
        );

        return $user;
    }

    public static function owner(int $id): User
    {
        $user = User::find($id);
        abort_unless(
            $user && in_array($user->status, ['active', 'suspended'], true)
            && $user->hasVerifiedEmail() && $user->onboarded_at,
            403,
            'Billing account is not available.'
        );

        return $user;
    }
}
