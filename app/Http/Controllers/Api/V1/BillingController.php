<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BillingInvoiceResource;
use App\Models\{Artist, ArtistPlaylist};
use App\Services\ArtistCapabilities;
use App\Services\Billing\{Billing, Checkouts, SubscriptionActions, Webhooks};
use Spark\Facades\DB;
use Spark\Http\{Request, Response};
use Spark\Http\Resources\JsonResource;

class BillingController extends Controller
{
    public function plans(): Response
    {
        $plans = [['code' => 'free', 'name' => 'Free', 'amount' => 0, 'currency' => 'usd', 'interval' => null, 'limits' => config('artists.plans.free')]];
        foreach (config('billing.plans') as $code => $plan) {
            $plans[] = [
                'code' => $code, 'name' => $plan['name'], 'amount' => $plan['amount'], 'currency' => 'usd', 'interval' => 'month',
                'offer_version' => config('billing.offer_version'), 'limits' => config('artists.plans.' . $code),
                'checkout_enabled' => (bool) $plan['price_id'] && (config('billing.environment') === 'test' || config('billing.live_sales_enabled')),
            ];
        }

        return json(['data' => ['offers' => $plans, 'environment' => config('billing.environment'), 'live_sales_enabled' => (bool) config('billing.live_sales_enabled'),
            'disclosures' => ['Monthly renewal; taxes may apply.', 'Cancel renewal at any time; verified paid access lasts through the paid period.',
                'Plan changes require the previous subscription and paid period to end.', 'EPK, TV, Spotlight and operator pilot placements are not subscription entitlements.',
                'Test mode is for integration testing. Live sales remain disabled until all paid benefits are available.']]]);
    }

    public function entitlements(Request $request, ArtistCapabilities $capabilities): Response
    {
        $id = $request->user('id');
        $artist = Artist::where('user_id', $id)->first();
        $access = Billing::access($id);

        return json(['data' => [
            'capabilities' => $capabilities->forUser($id), 'access_ends_at' => Billing::date($access?->ends_at),
            'usage' => ['links' => $artist ? DB::table('artist_links')->where('artist_id', $artist->id)->count() : 0,
                'playlists' => $artist ? ArtistPlaylist::where('artist_id', $artist->id)->count() : 0],
        ]]);
    }

    public function subscription(Request $request): Response
    {
        return json(['data' => Billing::subscriptionData($request->user('id'))]);
    }

    public function checkout(Request $request, Checkouts $checkouts): Response
    {
        $input = ReleaseController::input($request, [
            'plan_code' => ['required', 'string', ['in' => ['artist_starter', 'artist_pro']]],
            'offer_version' => ['required', 'string', 'max:40'],
        ]);
        $key = $request->header('Idempotency-Key', '');
        abort_unless(is_string($key), 422, 'Invalid Idempotency-Key.');

        return json(['data' => Checkouts::data($checkouts->create($request->user(), $input, $key))]);
    }

    public function checkoutStatus(Request $request, string $reference): Response
    {
        $attempt = Billing::table('checkouts')->where('user_id', $request->user('id'))->where('reference', $reference)->first();
        abort_unless((bool) $attempt, 404, 'Checkout not found.');
        // Polling is local and remains available during provider outages. Workers reconcile state.
        return json(['data' => Checkouts::data($attempt)]);
    }

    public function portal(Request $request, SubscriptionActions $actions): Response
    {
        ReleaseController::input($request, []);

        return json(['data' => ['url' => $actions->portal($request->user('id'))]]);
    }

    public function cancel(Request $request, SubscriptionActions $actions): Response
    {
        ReleaseController::input($request, []);

        return json(['data' => $actions->renewal($request->user('id'), true)]);
    }

    public function resume(Request $request, SubscriptionActions $actions): Response
    {
        ReleaseController::input($request, []);

        return json(['data' => $actions->renewal($request->user('id'), false)]);
    }

    public function invoices(Request $request): JsonResource
    {
        $input = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:50'], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);
        $query = Billing::table('invoices')->whereIn('subscription_id', Billing::table('subscriptions')->select('id')->where('user_id', $request->user('id')));

        return BillingInvoiceResource::collection($query->orderByRaw('issued_at DESC, id DESC')->paginate((int) $input->get('limit', 20)));
    }

    public function webhook(Request $request, Webhooks $webhooks): Response
    {
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $body = '';
        while (!$stream->eof() && strlen($body) <= 1048576) {
            $body .= $stream->read(min(8192, 1048577 - strlen($body)));
        }
        abort_if(strlen($body) > 1048576, 413, 'Webhook body too large.');
        $signature = $request->header('Stripe-Signature', '');
        abort_unless(is_string($signature), 400, 'Invalid signature header.');
        $webhooks->receive($body, $signature);

        return json(['received' => true]);
    }
}
