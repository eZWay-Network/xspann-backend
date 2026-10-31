<?php

namespace Tests\Support;

use App\Services\Billing\{PaymentProvider, Webhooks};

trait BillingFixtures
{
    protected FakeStripe $stripe;

    protected function billingSetup(): void
    {
        $this->app->mergeConfig(['billing' => [
            'environment' => 'test', 'stripe_account' => 'acct_xpn', 'webhook_secrets' => ['whsec_test'],
            'return_url' => 'https://web.example/billing/success', 'cancel_url' => 'https://web.example/billing/cancel',
            'portal_return_url' => 'https://web.example/billing', 'portal_configuration' => 'bpc_xpn',
            'plans' => ['artist_starter' => ['price_id' => 'price_starter'], 'artist_pro' => ['price_id' => 'price_pro']],
        ]]);
        $this->stripe = new FakeStripe();
        $this->app->instance(PaymentProvider::class, $this->stripe);
    }

    protected function checkout(string $plan = 'artist_pro', string $key = 'checkout-test-key'): object
    {
        $this->postJson('/api/v1/billing/checkout', ['plan_code' => $plan, 'offer_version' => config('billing.offer_version')], ['Idempotency-Key' => $key])->assertOk();

        return query('billing_checkouts')->orderBy('id', 'DESC')->first();
    }

    protected function event(string $type, string $object, ?string $id = null): int
    {
        $event = ['id' => $id ?? 'evt_' . bin2hex(random_bytes(8)), 'type' => $type, 'created' => time(),
            'livemode' => false, 'api_version' => '2024-06-20', 'data' => ['object' => ['id' => $object]]];
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $time = time();
        $service = app(Webhooks::class);
        $service->receive($body, 't=' . $time . ',v1=' . hash_hmac('sha256', "$time.$body", 'whsec_test'));
        $record = query('billing_webhooks')->where('event_id', $event['id'])->first();
        $service->process($record->id);

        return $record->id;
    }
}
