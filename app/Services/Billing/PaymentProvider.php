<?php

namespace App\Services\Billing;

interface PaymentProvider
{
    public function request(string $method, string $path, array $data = [], ?string $key = null): array;

    public function createCheckout(array $payload, string $key): array;

    public function retrieveCheckout(string $id): array;

    public function retrieveSubscription(string $id): array;

    public function createPortal(string $customer, string $configuration, string $returnUrl): array;

    public function setRenewal(string $subscription, bool $cancel, string $key): array;

    public function verifyWebhook(string $body, string $signature): array;
}
