<?php

namespace App\Services\Billing;

use Spark\Http\Client\Http;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function strlen;

/** Adapted from the supplied PaymentGateway/WebhookService; no SDK or automatic POST retry. */
class StripeProvider implements PaymentProvider
{
    public function request(string $method, string $path, array $data = [], ?string $key = null): array
    {
        $mode = config('billing.environment');
        $secret = (string) config('billing.stripe_secret');

        abort_unless(
            in_array($mode, ['test', 'live'], true)
            && (str_starts_with($secret, "sk_{$mode}_") || str_starts_with($secret, "rk_{$mode}_")),
            503,
            'Billing credentials are not configured for this environment.'
        );

        abort_if(config('app.debug'), 503, 'Disable debug logging before using billing.');
        abort_unless(preg_match('~^/[a-zA-Z0-9_/-]+$~D', $path), 503, 'Invalid provider operation.');

        $headers = [
            'Authorization' => 'Bearer ' . $secret,
            'Stripe-Version' => config('billing.stripe_version'),
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];
        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }

        try {
            $http = Http::make()
                ->withHeaders($headers)->withTimeout(15)->withRetry(0)
                ->withOptions([CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false]);
            $url = "https://api.stripe.com/v1$path";
            $response = $method === 'GET'
                ? $http->get($url, $data)
                : $http->method($method)->postFields(http_build_query($data), 'application/x-www-form-urlencoded')->send($url);
            $result = $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            $result = null;
        }

        abort_unless(
            is_array($result),
            503,
            'Billing provider unavailable. Retry the same operation with the same key.'
        );

        return $result;
    }

    public function createCheckout(array $payload, string $key): array
    {
        return $this->request('POST', '/checkout/sessions', $payload, $key);
    }

    public function retrieveCheckout(string $id): array
    {
        return $this->request('GET', '/checkout/sessions/' . rawurlencode($id), ['expand' => ['line_items']]);
    }

    public function retrieveSubscription(string $id): array
    {
        return $this->request('GET', '/subscriptions/' . rawurlencode($id));
    }

    public function createPortal(string $customer, string $configuration, string $returnUrl): array
    {
        return $this->request('POST', '/billing_portal/sessions', [
            'customer' => $customer,
            'configuration' => $configuration,
            'return_url' => $returnUrl,
        ]);
    }

    public function setRenewal(string $subscription, bool $cancel, string $key): array
    {
        return $this->request('POST', '/subscriptions/' . rawurlencode($subscription), [
            'cancel_at_period_end' => $cancel ? 'true' : 'false',
        ], $key);
    }

    public function verifyWebhook(string $body, string $signature): array
    {
        abort_if(strlen($body) > 1048576, 413, 'Webhook body too large.');
        $secrets = config('billing.webhook_secrets', []);

        abort_unless((bool) $secrets, 503, 'Webhook is not configured.');
        $parts = [];
        foreach (explode(',', $signature) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2) {
                $parts[$pair[0]][] = $pair[1];
            }
        }

        $timestamp = $parts['t'][0] ?? '';
        abort_unless(
            count($parts['t'] ?? []) === 1 && ctype_digit($timestamp)
            && abs(time() - (int) $timestamp) <= 300,
            400,
            'Invalid webhook timestamp.'
        );

        $valid = false;
        foreach ($secrets as $secret) {
            foreach ($parts['v1'] ?? [] as $candidate) {
                $valid = hash_equals(hash_hmac('sha256', "$timestamp.$body", $secret), $candidate) || $valid;
            }
        }

        abort_unless($valid, 400, 'Invalid webhook signature.');
        $event = json_decode($body, true);

        abort_unless(
            is_array($event) && is_string($event['id'] ?? null)
            && preg_match('/^evt_[a-zA-Z0-9_]{1,110}$/D', $event['id'])
            && is_string($event['type'] ?? null) && strlen($event['type']) <= 100
            && is_int($event['created'] ?? null) && $event['created'] > 0
            && is_array($event['data']['object'] ?? null),
            400,
            'A Stripe Snapshot event is required.'
        );

        abort_unless(
            ($event['livemode'] ?? null) === (config('billing.environment') === 'live')
            && empty($event['account']) && empty($event['context']),
            400,
            'Webhook account or environment mismatch.'
        );

        abort_unless(
            ($event['api_version'] ?? null) === config('billing.stripe_version'),
            400,
            'Webhook API version mismatch.'
        );

        return $event;
    }
}
