<?php

namespace App\Http;

use App\Http\Requests\Releases\EditReleaseRequest;

class BillingReleaseApiReference
{
    public static function catalog(): array
    {
        $result = [];
        $add = function ($action, $title, $description, $response, $rules = [], $example = [], $limit = null) use (&$result): void {
            $group = str_starts_with($action, 'Billing') ? 'Billing' : 'Releases';
            $status = '200';
            $errors = ['409' => 'State, ownership, version or idempotency conflict.', '503' => 'Required integration is unavailable or not configured.'];
            $result[$action] = compact('group', 'title', 'description', 'response', 'rules', 'example', 'limit', 'status', 'errors');
        };
        $date = '2026-10-31T15:00:00Z';
        $checkout = ['reference' => 'opaque-local-reference', 'status' => 'pending', 'url' => 'https://checkout.stripe.com/c/pay/example', 'expires_at' => $date, 'access_active' => false, 'paid_through' => null];
        $subscription = ['status' => 'active', 'plan' => 'artist_pro', 'effective_plan' => 'artist_pro', 'paid_through' => $date, 'cancel_at_period_end' => false, 'period_end' => $date, 'renewal_pending' => false];
        $release = [
            'id' => 1, 'version' => 2, 'caption' => 'Next release', 'visibility' => 'public', 'reuse_content' => true,
            'rights_version' => config('policies.rights_version'), 'rights_confirmed_at' => $date,
            'processing_state' => 'ready', 'publication_state' => 'draft', 'publication_intent' => 'draft', 'status' => 'draft',
            'publish_at' => null, 'timezone' => null, 'published_at' => null, 'error_code' => null, 'moderation_status' => 'approved',
            'preview' => ['video' => '/api/v1/me/videos/1/preview/video', 'thumbnail' => null, 'original_audio' => null],
            'created_at' => $date, 'updated_at' => $date,
        ];
        $version = ['version' => ['required', 'integer', 'min:1']];

        $add('BillingController@plans', 'Plan catalog', 'USD integer cents, monthly offers and capability limits. No provider price IDs or purchase URLs. Live sales are disabled until all paid benefits and the sales review are complete. Test mode remains available.', ['offers' => [['code' => 'free', 'amount' => 0, 'currency' => 'usd', 'limits' => config('artists.plans.free')]], 'environment' => 'test', 'live_sales_enabled' => false, 'disclosures' => ['Monthly renewal; taxes may apply.']]);
        $add('BillingController@entitlements', 'Effective access', 'Shared web/native contract. Access derives from unrevoked provider-verified paid periods, not Checkout completion. No payment links. Existing paid content survives downgrade; excess playlists become read-only.', ['capabilities' => ['plan' => 'free', ...config('artists.plans.free')], 'access_ends_at' => null, 'usage' => ['links' => 0, 'playlists' => 0]]);
        $add('BillingController@subscription', 'Your subscription', 'Web billing owner, including restricted recovery access for suspended accounts. Local authoritative status remains readable during provider outages. A failed renewal never adds a paid period. Cancellation preserves already-paid time.', $subscription);
        $add('BillingController@checkout', 'Create Checkout', 'Web only. Requires an artist profile and Idempotency-Key header (8–200 visible ASCII characters). Supply the current offer_version. Persisted before Stripe; retry the same key after a timeout. Different payload with the same key, or another pending/live subscription returns 409. Fixed server return URLs. Unknown fields rejected.', $checkout,
            ['plan_code' => ['required', 'string', 'in:artist_starter,artist_pro'], 'offer_version' => ['required', 'string', 'max:40']], ['plan_code' => 'artist_pro', 'offer_version' => config('billing.offer_version')]);
        $add('BillingController@checkoutStatus', 'Checkout result', 'Owner-only local reference; poll after the hosted return. status=pending|completed|expired|failed. completed does not imply paid access. Reconciliation continues independently of browser redirects.', $checkout);
        $add('BillingController@portal', 'Open billing portal', 'Web only. No request body. Provider customer and fixed return URL are selected server-side. Portal configuration must disable plan switching, pauses and retention offers and cancel at period end.', ['url' => 'https://billing.stripe.com/p/session/example']);
        $add('BillingController@cancel', 'Cancel renewal', 'Web only. Suspended owners retain this recovery action. No request body. Idempotent cancellation at period end. A durable pending provider operation survives timeouts. Already-paid access remains.', [...$subscription, 'cancel_at_period_end' => true]);
        $add('BillingController@resume', 'Resume renewal', 'Web only. Undo pending cancellation while the subscription is still open and its period has not ended. Does not resurrect an ended subscription.', $subscription);
        $add('BillingController@invoices', 'Your invoices', 'Web only. Owned invoice history and provider-hosted receipt links; no arbitrary provider customer or invoice lookup.', ['reference' => 'in_example', 'status' => 'paid', 'amount_paid' => 1999, 'amount_due' => 1999, 'currency' => 'usd', 'issued_at' => $date, 'receipt_url' => 'https://invoice.stripe.com/i/example'], limit: 20);
        $add('BillingController@webhook', 'Stripe webhook receipt', 'Provider callback; raw JSON and Stripe-Signature header. Maximum 1 MiB, 300-second tolerance, rotating secrets, account/environment and pinned 2024-06-20 Snapshot schema checks. Durable encrypted inbox; duplicates acknowledge 200. No JWT; browser billing routes retain authentication.', ['received' => true]);
        $add('ReleaseController@index', 'Your releases', 'Owned artist videos, including drafts and failures. Defaults 20/max 50. Preview URLs require the owner bearer token. Filtering never changes visibility.', $release, [
            'processing_state' => ['sometimes', 'string', 'in:processing,ready,failed'],
            'publication_state' => ['sometimes', 'string', 'in:draft,scheduled,published'],
        ], limit: 20);
        $add('ReleaseController@show', 'Release detail', 'Owner artist only. Separate encoding/publication state, current version, requested UTC time, IANA timezone, actual publication time and safe error code. Restricted media has no preview.', $release);
        $add('ReleaseController@update', 'Edit a draft', 'Allowed caption, visibility, reuse_content and paired rights_confirmed/rights_version plus current version. Only drafts; cancel a schedule first. No media replacement. Every successful edit increments version; stale edits return 409.', $release, EditReleaseRequest::class, ['version' => 2, 'caption' => 'Next release']);
        $add('ReleaseController@schedule', 'Schedule or reschedule', 'Pro only. Ready, eligible unpublished video. publish_at requires ISO-8601 seconds and offset matching an IANA timezone; reject past, nonexistent local times and mismatched offsets. DST repeated times are distinguished by their explicit offset. Minute worker catches up after downtime and rechecks entitlement/rights/account. Loss of eligibility pauses to draft with notice.', [...$release, 'publication_state' => 'scheduled', 'status' => 'scheduled'], [...$version, 'publish_at' => ['required', 'string'], 'timezone' => ['required', 'string']], ['version' => 2, 'publish_at' => '2027-01-01T18:00:00+06:00', 'timezone' => 'Asia/Dhaka']);
        $add('ReleaseController@cancel', 'Cancel schedule', 'Back to draft. Available without Pro, even after access ends. Clears requested time and automatic intent. Requires current version.', $release, $version, ['version' => 3]);
        $add('ReleaseController@publish', 'Publish now', 'Free capability for an owning artist. Ready/eligible draft or schedule only. Explicit immediate action; never automatically publish early on downgrade. Requires version.', [...$release, 'publication_state' => 'published', 'status' => 'published'], $version, ['version' => 2]);
        $add('ReleaseController@retry', 'Retry video encoding', 'Any active owner; no artist required. Failed/interrupted processing only. Repeated requests do not enqueue a fresh processing job until one hour has elapsed. Preserves publication intent; cannot resurrect deleted or unavailable-source media.', $release);
        $add('ReleaseController@preview', 'Private media preview', 'Owner bearer token required. asset=video|thumbnail|original_audio; never a storage path. Local binary/Range response (200/206, invalid range 416), or authorized S3 redirect valid 60 seconds. no-store. Draft original, render and sound remain private.', ['delivery' => 'binary or short-lived redirect']);
        $add('MediaController@show', 'Managed local media delivery', 'Opaque signed URL returned in resources. expires/signature are required query parameters. Every request rechecks current publication, account visibility, block/reuse rules; an unpublished draft never becomes public via this route. Binary/Range response.', ['delivery' => 'binary']);

        return $result;
    }
}
