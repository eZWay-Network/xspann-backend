<?php

namespace Tests\Support;

use App\Services\Billing\StripeProvider;

class FakeStripe extends StripeProvider
{
    public array $objects = [];
    public array $calls = [];
    public array $keys = [];
    public bool $loseCheckoutResponse = false;
    public bool $loseCustomerResponse = false;
    public bool $loseRenewalResponse = false;
    public bool $loseCustomerDeleteResponse = false;
    public int $listPageSize = 100;

    public function request(string $method, string $path, array $data = [], ?string $key = null): array
    {
        $this->calls[] = compact('method', 'path', 'data', 'key');
        if ($key && isset($this->keys[$key])) {
            return $this->keys[$key];
        }
        if ($method === 'GET') {
            if ($path === '/account') {
                return ['id' => 'acct_xpn'];
            }
            if (str_starts_with($path, '/prices/')) {
                return ['id' => basename($path), 'livemode' => false, 'active' => true, 'currency' => 'usd',
                    'unit_amount' => str_contains($path, 'pro') ? 1999 : 499,
                    'recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'licensed']];
            }
            if (in_array($path, ['/invoices', '/checkout/sessions', '/refunds', '/disputes', '/customers'], true)) {
                $prefix = $path . '/';
                $items = [];
                foreach ($this->objects as $name => $item) {
                    if (str_starts_with($name, $prefix) && (!isset($data['subscription']) || ($item['subscription'] ?? null) === $data['subscription'])
                        && (!isset($data['customer']) || ($item['customer'] ?? null) === $data['customer'])
                        && (!isset($data['charge']) || ($item['charge'] ?? null) === $data['charge'])) {
                        $items[] = $item;
                    }
                }
                if (isset($data['starting_after'])) {
                    $position = array_search($data['starting_after'], array_column($items, 'id'), true);
                    $items = $position === false ? [] : array_slice($items, $position + 1);
                }
                $limit = min($data['limit'] ?? 100, $this->listPageSize);

                return ['data' => array_slice($items, 0, $limit), 'has_more' => count($items) > $limit];
            }
            if (isset($this->objects[$path])) {
                return $this->objects[$path];
            }
            throw new \RuntimeException('Missing fake GET object: ' . $path);
        }
        if ($path === '/customers') {
            $id = 'cus_' . (count($this->keys) + 1);
            $result = ['id' => $id, 'livemode' => false, 'metadata' => $data['metadata']];
            $this->objects['/customers/' . $id] = $result;
        } elseif ($path === '/checkout/sessions') {
            $id = 'cs_test_' . (count($this->keys) + 1);
            $price = $data['line_items'][0]['price'];
            $result = [
                'id' => $id, 'livemode' => false, 'mode' => 'subscription', 'status' => 'open',
                'customer' => $data['customer'], 'client_reference_id' => $data['client_reference_id'],
                'metadata' => $data['metadata'], 'url' => 'https://checkout.stripe.com/c/pay/' . $id,
                'expires_at' => time() + 86400, 'subscription' => null,
                'line_items' => ['data' => [['price' => ['id' => $price], 'quantity' => 1]], 'has_more' => false],
            ];
            $this->objects['/checkout/sessions/' . $id] = $result;
        } elseif (str_starts_with($path, '/subscriptions/')) {
            $result = $this->objects[$path];
            if ($method === 'DELETE') {
                $result['status'] = 'canceled';
            } else {
                $result['cancel_at_period_end'] = $data['cancel_at_period_end'] === 'true';
            }
            $this->objects[$path] = $result;
        } elseif (str_ends_with($path, '/expire')) {
            $sessionPath = substr($path, 0, -7);
            $result = $this->objects[$sessionPath];
            $result['status'] = 'expired';
            $this->objects[$sessionPath] = $result;
        } elseif ($method === 'DELETE' && str_starts_with($path, '/customers/')) {
            abort_if(!empty($this->objects[$path]['deleted']), 404, 'Customer is already deleted.');
            $result = ['id' => basename($path), 'deleted' => true];
            $this->objects[$path] = $result;
        } elseif ($path === '/billing_portal/sessions') {
            $result = ['url' => 'https://billing.stripe.com/p/session/own'];
        } else {
            throw new \RuntimeException('Missing fake operation: ' . $path);
        }
        if ($key) {
            $this->keys[$key] = $result;
        }
        if ($path === '/checkout/sessions' && $this->loseCheckoutResponse) {
            $this->loseCheckoutResponse = false;
            abort(503, 'Simulated lost response.');
        }
        if ($path === '/customers' && $this->loseCustomerResponse) {
            $this->loseCustomerResponse = false;
            abort(503, 'Simulated lost customer response.');
        }
        if ($method === 'POST' && str_starts_with($path, '/subscriptions/') && $this->loseRenewalResponse) {
            $this->loseRenewalResponse = false;
            abort(503, 'Simulated lost renewal response.');
        }
        if ($method === 'DELETE' && str_starts_with($path, '/customers/') && $this->loseCustomerDeleteResponse) {
            $this->loseCustomerDeleteResponse = false;
            abort(503, 'Simulated lost customer deletion response.');
        }

        return $result;
    }

    public function complete(string $sessionId, bool $paid = true): string
    {
        $session = &$this->objects['/checkout/sessions/' . $sessionId];
        $subscription = 'sub_' . $sessionId;
        $invoice = 'in_' . $sessionId;
        $charge = 'ch_' . $sessionId;
        $price = $session['line_items']['data'][0]['price']['id'];
        $amount = str_contains($price, 'pro') ? 1999 : 499;
        $session['status'] = 'complete';
        $session['subscription'] = $subscription;
        $this->objects['/subscriptions/' . $subscription] = [
            'id' => $subscription, 'livemode' => false, 'customer' => $session['customer'],
            'metadata' => $session['metadata'], 'status' => $paid ? 'active' : 'incomplete',
            'items' => ['data' => [['price' => ['id' => $price], 'quantity' => 1]], 'has_more' => false],
            'current_period_end' => time() + 30 * 86400, 'cancel_at_period_end' => false,
        ];
        $this->objects['/invoices/' . $invoice] = [
            'id' => $invoice, 'livemode' => false, 'subscription' => $subscription, 'customer' => $session['customer'],
            'currency' => 'usd', 'status' => $paid ? 'paid' : 'open', 'paid' => $paid,
            'amount_paid' => $paid ? $amount : 0, 'amount_due' => $amount, 'amount_remaining' => $paid ? 0 : $amount,
            'created' => time(), 'charge' => $charge, 'billing_reason' => 'subscription_create',
            'status_transitions' => ['paid_at' => $paid ? time() : null],
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/' . $invoice,
            'lines' => ['data' => [[
                'type' => 'subscription', 'subscription' => $subscription, 'price' => ['id' => $price],
                'quantity' => 1, 'amount' => $amount, 'proration' => false,
                'period' => ['start' => time() - 60, 'end' => time() + 30 * 86400],
            ]], 'has_more' => false],
        ];
        $this->objects['/charges/' . $charge] = [
            'id' => $charge, 'livemode' => false, 'invoice' => $invoice, 'customer' => $session['customer'],
            'paid' => $paid, 'amount' => $amount, 'amount_refunded' => 0, 'currency' => 'usd',
            'captured' => $paid, 'amount_captured' => $paid ? $amount : 0, 'disputed' => false,
        ];

        return $invoice;
    }
}
