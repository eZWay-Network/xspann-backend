<?php

namespace App\Http\Controllers\Staff\Operations;

use App\Http\Requests\Staff\{ListFilters, RecoveryRequest};
use App\Http\Resources\Staff\Records;
use App\Services\Staff\{Context, Operations};
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class CommunicationController
{
    public function index(Request $request): Response
    {
        $choices = ['status' => ['draft', 'queued', 'completed', 'cancelled']];
        $filters = ListFilters::read($request, $choices);
        $query = DB::table('announcements')->select(['id', 'status', 'recipient_count', 'paid_period_id', 'created_at', 'accepted_at']);
        if ($filters['status']) {
            $query->where('status', $filters['status']);
        }
        if ($filters['q'] !== '') {
            $query->where('id', ctype_digit($filters['q']) ? (int) $filters['q'] : 0);
        }
        $context = Context::from($request);
        $canRetry = $context->can('communications.retry');
        $listing = Records::listing($query, $filters, fn ($row) => Records::row($row, 'Announcement #' . $row->id, null, $row->status,
            $row->recipient_count . ' accepted recipients · period #' . ($row->paid_period_id ?? '—'), 'UTC')
            + ['recovery' => $canRetry && $row->status === 'queued'
                ? ['label' => 'Recover pending recipients', 'url' => (string) route('staff.communications.retry', ['record' => $row->id]), 'key' => bin2hex(random_bytes(16))] : null],
            'staff.communications', $choices);
        $ids = array_column($listing['rows'], 'id');
        $counts = $ids ? DB::table('announcement_deliveries')->whereIn('announcement_id', $ids)
            ->selectRaw('announcement_id, status, COUNT(*) AS total')->groupBy(['announcement_id', 'status'])->get()->all() : [];
        foreach ($listing['rows'] as &$row) {
            foreach ($counts as $count) {
                if ((int) $count->announcement_id === $row['id']) {
                    $row['description'] .= ' · ' . (int) $count->total . ' ' . $count->status;
                }
            }
        }
        unset($row);
        $pending = DB::table('announcement_deliveries as d')->join('announcements as a', 'a.id', '=', 'd.announcement_id')
            ->where('d.status', 'pending')->selectRaw('COUNT(*) AS recipients, MIN(a.accepted_at) AS oldest')->first();

        return Inertia::render('operations/communications', $listing + [
            'page' => ['title' => 'Communications', 'breadcrumbs' => []],
            'description' => 'Aggregate in-app delivery only. Retries recheck consent and paid periods; suppressed recipients stay suppressed.',
            'delivery' => ['pending' => (int) $pending->recipients, 'oldest_at' => \App\Services\Billing\Billing::date($pending->oldest)],
            'outbox' => ['pending' => DB::table('realtime_outbox')->whereNull('completed_at')->count(),
                'oldest_at' => \App\Services\Billing\Billing::date(DB::table('realtime_outbox')->whereNull('completed_at')->selectRaw('MIN(created_at) AS earliest')->first()?->earliest)],
            'quota' => ['announcements' => (int) config('artists.plans.artist_pro.fan_announcements'),
                'recipients' => (int) config('artists.plans.artist_pro.announcement_recipients')],
            'realtime_action' => $canRetry ? ['label' => 'Recover up to 100 due realtime hints',
                'url' => (string) route('staff.realtime.recover'), 'key' => bin2hex(random_bytes(16))] : null,
        ]);
    }

    public function retry(RecoveryRequest $request, int $record): Response
    {
        return $this->queue($request, 'communications.retry', $record);
    }

    public function realtime(RecoveryRequest $request): Response
    {
        return $this->queue($request, 'realtime.recover', 0);
    }

    private function queue(RecoveryRequest $request, string $type, int $id): Response
    {
        $operation = Operations::enqueue(Context::from($request), $type, $id, $request->validated()->toArray());
        Inertia::flash('success', 'Recovery queued. Follow the delivery outcome here.');

        return Inertia::redirect((string) route('staff.operations.show', ['operation' => $operation]), 303);
    }
}
