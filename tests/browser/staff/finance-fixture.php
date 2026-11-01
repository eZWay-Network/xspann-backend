<?php

require dirname(__DIR__, 3) . '/vendor/autoload.php';
$directory = $argv[1] ?? '';
if (!str_starts_with($directory, '/private/tmp/xpn-staff-') || !is_file($directory . '/config.json')) {
    throw new RuntimeException('Only isolated staff fixture databases are supported.');
}
$_ENV['APP_ENV'] = 'testing';
$app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
$app->mergeConfig(json_decode(file_get_contents($directory . '/config.json'), true, flags: JSON_THROW_ON_ERROR));
$app->withQueue();
if (($argv[2] ?? '') === 'worker') {
    (new \App\Jobs\StaffMaintenance())->handle();
    echo 'processed';
    exit;
}
$tag = bin2hex(random_bytes(5));
$owner = \App\Models\User::create([
    'name' => 'Finance fixture', 'username' => 'finance_fixture_' . $tag, 'email' => $tag . '@example.test',
    'status' => 'active', 'email_verified_at' => now(), 'onboarded_at' => now(), 'password' => 'FixturePassword123!',
]);
$now = gmdate('Y-m-d H:i:s');
$catalog = query('billing_catalog')->insert([
    'provider' => 'stripe', 'environment' => 'test', 'plan_code' => 'artist_pro', 'offer_version' => $tag,
    'price_id' => 'price_' . $tag, 'amount' => 1999, 'currency' => 'usd', 'created_at' => $now, 'updated_at' => $now,
]);
$customer = query('billing_customers')->insert([
    'user_id' => $owner->id, 'provider' => 'stripe', 'environment' => 'test', 'reference' => 'customer_' . $tag,
    'customer_id' => 'cus_' . $tag, 'created_at' => $now, 'updated_at' => $now,
]);
$checkout = query('billing_checkouts')->insert([
    'user_id' => $owner->id, 'customer_id' => $customer, 'catalog_id' => $catalog, 'provider' => 'stripe', 'environment' => 'test',
    'reference' => 'checkout_' . $tag, 'idempotency_hash' => hash('sha256', $tag), 'request_hash' => hash('sha256', $tag),
    'status' => 'completed', 'created_at' => $now, 'updated_at' => $now,
]);
$subscription = query('billing_subscriptions')->insert([
    'user_id' => $owner->id, 'customer_id' => $customer, 'catalog_id' => $catalog, 'checkout_id' => $checkout,
    'provider' => 'stripe', 'environment' => 'test', 'subscription_id' => 'sub_' . $tag, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
]);
$invoice = query('billing_invoices')->insert([
    'subscription_id' => $subscription, 'provider' => 'stripe', 'environment' => 'test', 'invoice_id' => 'in_' . $tag,
    'status' => 'paid', 'amount_paid' => 1999, 'amount_due' => 1999, 'currency' => 'usd', 'issued_at' => $now, 'created_at' => $now, 'updated_at' => $now,
]);
query('billing_paid_periods')->insert([
    'subscription_id' => $subscription, 'invoice_id' => $invoice, 'starts_at' => gmdate('Y-m-d H:i:s', time() - 60),
    'ends_at' => gmdate('Y-m-d H:i:s', time() + 86400), 'created_at' => $now, 'updated_at' => $now,
]);
$adjustment = query('billing_adjustments')->insert([
    'invoice_id' => $invoice, 'provider' => 'stripe', 'environment' => 'test', 'object_id' => 're_' . $tag,
    'kind' => 'refund', 'status' => 'succeeded', 'amount' => 100, 'currency' => 'usd', 'created_at' => $now, 'updated_at' => $now,
]);
$webhook = query('billing_webhooks')->insert([
    'provider' => 'stripe', 'environment' => 'test', 'event_id' => 'evt_' . $tag, 'type' => 'fixture.unknown', 'status' => 'failed',
    'payload' => \Spark\Facades\Hash::encrypt(json_encode(['type' => 'fixture.unknown', 'data' => ['object' => ['id' => 'fixture']]])),
    'created_at' => $now, 'updated_at' => $now,
]);
echo json_encode(compact('subscription', 'invoice', 'checkout', 'adjustment', 'webhook'), JSON_THROW_ON_ERROR);
