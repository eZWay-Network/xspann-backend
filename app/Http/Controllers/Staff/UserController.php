<?php

namespace App\Http\Controllers\Staff;

use App\Http\Requests\Staff\{ListFilters, ReasonRequest};
use App\Http\Resources\Staff\Records;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class UserController
{
    public function index(Request $request): Response
    {
        $context = Context::from($request);
        $choices = ['status' => ['active', 'suspended', 'deleting']];
        $filters = ListFilters::read($request, $choices);
        $query = DB::table('users')->select(['id', 'username', 'name', 'status', 'email_verified_at', 'onboarded_at', 'created_at'])
            ->selectSub(DB::table('artists')->select('id')->whereColumn('artists.user_id', 'users.id')->limit(1), 'artist_id')
            ->selectSub(DB::table('staff_accounts')->select('id')->whereColumn('staff_accounts.user_id', 'users.id')->where('enabled', true)->limit(1), 'staff_id');
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if ($filters['q'] !== '') {
            $query->where(function ($query) use ($filters, $context): void {
                $query->whereContains('username', $filters['q'])->orWhere('id', ctype_digit($filters['q']) ? (int) $filters['q'] : 0);
                if ($context->can('users.contact.view')) {
                    $query->orWhere('email', $filters['q']);
                }
            });
        }

        return Inertia::render('users/index', Records::listing($query, $filters, fn ($row) => Records::row($row,
            $row->name . ' · @' . $row->username, (string) route('staff.users.show', ['user' => $row->id]), $row->status,
            ($row->email_verified_at ? 'Verified' : 'Unverified') . ' · ' . ($row->onboarded_at ? 'Onboarded' : 'Onboarding pending')
            . ($row->artist_id ? ' · Artist' : '') . ($row->staff_id ? ' · Staff' : '')), 'staff.users.index', $choices) + [
                'page' => ['title' => 'Users', 'breadcrumbs' => []], 'description' => 'Account status, onboarding and related activity.',
            ]);
    }

    public function show(Request $request, int $user): Response
    {
        $context = Context::from($request);
        $row = DB::table('users')->select(['id', 'username', 'name', 'bio', 'status', 'email_verified_at', 'onboarded_at', 'created_at'])->where('id', $user)->firstOrFail();
        $links = [];
        if ($context->can('artists.view')) {
            foreach (DB::table('artists')->where('user_id', $user)->select(['id', 'stage_name'])->get() as $artist) {
                $links[] = ['label' => $artist->stage_name, 'url' => (string) route('staff.artists.show', ['artist' => $artist->id])];
            }
        }
        if ($context->can('content.view')) {
            $links[] = ['label' => 'Videos', 'url' => (string) route('staff.videos.index')->withQuery(['owner_id' => $user])];
            $links[] = ['label' => 'Audio', 'url' => (string) route('staff.audios.index')->withQuery(['owner_id' => $user])];
        }
        $history = $context->can('reports.view') ? DB::table('moderation_decisions')->where('subject_user_id', $user)
            ->select(['id', 'report_id', 'action', 'created_at'])->orderBy('id', 'desc')->limit(20)->all() : [];

        return Inertia::render('users/show', [
            'page' => ['title' => $row->name, 'breadcrumbs' => []],
            'fields' => Records::fields($row, ['id', 'username', 'name', 'bio', 'status', 'email_verified_at', 'onboarded_at', 'created_at']),
            'links' => $links,
            'history' => array_map(fn ($decision) => ['label' => ucfirst($decision->action) . ' · case #' . $decision->report_id,
                'url' => (string) route('staff.reports.show', ['report' => $decision->report_id])], $history),
            'contact_url' => $context->can('users.contact.view') ? (string) route('staff.users.contact', ['user' => $user]) : null,
            'case_target' => $context->can('content.case.create') && $user !== (int) $context->user->id
                && User::visibleTo(null)->whereKey($user)->exists() ? ['type' => 'user', 'id' => $user, 'url' => (string) route('staff.cases.create')] : null,
        ]);
    }

    public function contact(ReasonRequest $request, int $user): Response
    {
        $row = DB::table('users')->select(['id', 'email'])->where('id', $user)->firstOrFail();
        AuditTrail::record('staff.contact_revealed', ['user_id' => $user, 'reason' => $request->validated('reason')], Context::from($request)->user->id, 'staff');

        return json(['email' => $row->email]);
    }
}
