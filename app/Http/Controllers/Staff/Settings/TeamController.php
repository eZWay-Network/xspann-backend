<?php

namespace App\Http\Controllers\Staff\Settings;

use App\Http\Requests\Staff\{InviteRequest, MemberRequest, ReasonRequest};
use App\Services\Staff\{Context, Permissions, Sessions, StaffException, Team};
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class TeamController
{
    public function index(Request $request): Response
    {
        $input = $request->all();

        foreach (['page', 'invitations_page'] as $key) {
            if (isset($input[$key]) && (!is_scalar($input[$key]) || !preg_match('/^[1-9][0-9]{0,5}$/D', (string) $input[$key]))) {
                throw new StaffException('Choose a valid page.', 422, $key);
            }
        }
        $size = Context::from($request)->preferences()['page_size'];
        $members = DB::table('staff_accounts')->join('users', 'users.id', '=', 'staff_accounts.user_id')
            ->select(['staff_accounts.id', 'staff_accounts.user_id', 'staff_accounts.role', 'staff_accounts.enabled', 'staff_accounts.version', 'users.name', 'users.username'])
            ->orderBy('staff_accounts.id')->paginate($size, 'page');
        $invitations = DB::table('staff_invitations')->join('users', 'users.id', '=', 'staff_invitations.user_id')
            ->whereNotNull('staff_invitations.active_key')->where('staff_invitations.expires_at', '>', Sessions::timestamp())
            ->select(['staff_invitations.id', 'staff_invitations.role', 'staff_invitations.expires_at', 'users.name', 'users.username'])
            ->orderBy('staff_invitations.id', 'desc')->paginate($size, 'invitations_page');

        return Inertia::render('settings/team', [
            'members' => array_map(fn ($row) => [
                'id' => (int) $row->id, 'user_id' => (int) $row->user_id, 'name' => $row->name, 'username' => $row->username,
                'role' => $row->role, 'enabled' => (bool) $row->enabled, 'version' => (int) $row->version,
                'action_url' => (string) route('staff.team.update', ['member' => $row->id]),
                'show_url' => (string) route('staff.team.show', ['member' => $row->id]),
                'can_change_role' => (int) $row->user_id !== Context::from($request)->user->id,
            ], $members->items()),
            'invitations' => array_map(fn ($row) => [
                'id' => (int) $row->id, 'name' => $row->name, 'username' => $row->username, 'role' => $row->role,
                'expires_at' => Sessions::iso($row->expires_at),
                'cancel_url' => (string) route('staff.team.cancel', ['invitation' => $row->id]),
            ], $invitations->items()),
            'pagination' => ['members' => $members->paginationMeta(), 'invitations' => $invitations->paginationMeta()],
            'list_url' => (string) route('staff.team'),
            'roles' => array_map(fn ($role) => ['value' => $role, 'label' => ucwords(str_replace('_', ' ', $role))], Permissions::roles()),
            'invite_url' => (string) route('staff.team.invite'),
            'confirmation_url' => (string) route('staff.challenge')->withQuery(['destination' => '/settings/team']),
            'page' => ['title' => 'Team & access', 'breadcrumbs' => []],
        ]);
    }

    public function show(Request $request, int $member): Response
    {
        $row = DB::table('staff_accounts')->select(['id', 'user_id', 'role', 'enabled', 'version', 'created_at', 'updated_at'])->where('id', $member)->firstOrFail();
        $fields = \App\Http\Resources\Staff\Records::fields($row, ['id', 'user_id', 'role', 'enabled', 'version', 'created_at', 'updated_at'], 'UTC');
        $fields[] = ['label' => 'Active browser sessions', 'value' => DB::table('staff_sessions')->where('user_id', $row->user_id)
            ->whereNull('revoked_at')->where('expires_at', '>', Sessions::timestamp())->where('absolute_expires_at', '>', Sessions::timestamp())->count()];

        return Inertia::render('settings/member', [
            'page' => ['title' => 'Staff member #' . $member, 'breadcrumbs' => []], 'fields' => $fields,
            'links' => [['label' => 'Manage team access', 'url' => (string) route('staff.team')]],
            'permissions' => Permissions::forRole($row->role),
        ]);
    }

    public function invite(InviteRequest $request): Response
    {
        $input = $request->validated();
        $url = Team::invite(Context::from($request), $input->email, $input->role, $input->reason);
        Inertia::flash(['success' => 'Invitation created. Share the one-time link with your colleague.', 'invitation_url' => $url]);

        return Inertia::redirect((string) route('staff.team'), 303);
    }

    public function update(MemberRequest $request, int $member): Response
    {
        Team::update(Context::from($request), $member, $request->validated()->toArray());
        Inertia::flash('success', 'Staff access updated and existing sessions revoked.');

        return Inertia::redirect((string) route('staff.team'), 303);
    }

    public function cancel(ReasonRequest $request, int $invitation): Response
    {
        Team::cancelInvitation(Context::from($request), $invitation, $request->validated('reason'));
        Inertia::flash('success', 'Invitation cancelled.');

        return Inertia::redirect((string) route('staff.team'), 303);
    }
}
