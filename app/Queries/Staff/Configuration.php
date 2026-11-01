<?php

namespace App\Queries\Staff;

use App\Http\Resources\Staff\Records;

/** Explicit, non-secret deployment projections. Never serialize a configuration subtree. */
final class Configuration
{
    public static function catalog(): array
    {
        $sections = [];
        foreach (['free', 'artist_starter', 'artist_pro'] as $plan) {
            $fields = [['label' => 'Plan', 'value' => $plan], ['label' => 'Billing interval', 'value' => $plan === 'free' ? 'Free' : 'Monthly'],
                ['label' => 'Price (USD minor units)', 'value' => (int) config("billing.plans.$plan.amount", 0)],
                ['label' => 'Provider price configured', 'value' => (bool) config("billing.plans.$plan.price_id")]];
            foreach (['links', 'playlists', 'featured_release', 'enhanced_profile', 'enhanced_directory_card', 'analytics_30_days', 'release_scheduling', 'fan_announcements', 'announcement_recipients', 'monthly_boost_credit_cents'] as $key) {
                $fields[] = ['label' => Records::label($key), 'value' => config("artists.plans.$plan.$key")];
            }
            $sections[] = ['title' => Records::label($plan), 'fields' => $fields];
        }
        $sections[] = ['title' => 'Release policy', 'fields' => [
            ['label' => 'Live sales enabled', 'value' => (bool) config('billing.live_sales_enabled')],
            ['label' => 'Offer version', 'value' => config('billing.offer_version')],
            ['label' => 'Deferred products', 'value' => 'Annual plans, trials, coupons, EPK, TV airings, paid Spotlight, creator payouts'],
        ]];

        return $sections;
    }

    public static function policies(): array
    {
        $sections = [];
        foreach (['terms', 'privacy', 'community', 'marketing'] as $key) {
            $sections[] = ['title' => ucfirst($key), 'fields' => [
                ['label' => 'Version', 'value' => config("policies.documents.$key.version")],
                ['label' => 'Public URL', 'value' => config("policies.documents.$key.url")],
                ['label' => 'Required', 'value' => (bool) config("policies.documents.$key.required")],
            ]];
        }
        $fields = [];
        foreach (['rights_version', 'minimum_age', 'export_hours', 'closed_report_retention_days', 'audit_retention_days', 'completed_privacy_retention_days'] as $key) {
            $fields[] = ['label' => Records::label($key), 'value' => config('policies.' . $key)];
        }
        $fields[] = ['label' => 'Financial retention days', 'value' => (int) config('billing.retention_days')];
        $sections[] = ['title' => 'Retention & rights', 'fields' => $fields];

        return $sections;
    }

    public static function integrations(): array
    {
        $configured = fn (bool $value) => $value ? 'configured — health not verified' : 'missing';
        return [['title' => 'Deployment readiness', 'fields' => [
            ['label' => 'Stripe', 'value' => $configured((bool) config('billing.stripe_secret') && (bool) config('billing.stripe_account') && (bool) config('billing.webhook_secrets'))],
            ['label' => 'Billing environment', 'value' => config('billing.environment')],
            ['label' => 'Stripe API version', 'value' => config('billing.stripe_version')],
            ['label' => 'Relay', 'value' => $configured((bool) config('relay.apps.0.key') && (bool) config('relay.apps.0.secret'))],
            ['label' => 'Google identity', 'value' => $configured((bool) config('app.google_client_id'))],
            ['label' => 'Mail transport', 'value' => config('mail.transport')],
            ['label' => 'Mail', 'value' => $configured(config('mail.transport') !== 'log' && (bool) config('mail.from.address'))],
            ['label' => 'Media storage driver', 'value' => config('storage.disks.' . config('storage.default') . '.driver')],
            ['label' => 'Private release storage', 'value' => config('storage.release_disk')],
            ['label' => 'S3', 'value' => $configured((bool) config('storage.disks.s3.key') && (bool) config('storage.disks.s3.secret') && (bool) config('storage.disks.s3.bucket'))],
            ['label' => 'Queue driver', 'value' => config('queue.connections.' . config('queue.default') . '.driver')],
            ['label' => 'Promotion booking', 'value' => !\App\Services\Promotions\Availability::configured() ? 'missing' : (\App\Services\Promotions\Availability::healthy() ? 'observed availability; sales gates still apply' : 'stale or unavailable')],
        ]]];
    }
}
