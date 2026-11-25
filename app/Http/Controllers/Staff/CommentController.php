<?php

namespace App\Http\Controllers\Staff;

use App\Http\Requests\Staff\ListFilters;
use App\Http\Resources\Staff\{ModerationActions, Records};
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class CommentController
{
    public function index(Request $request): Response
    {
        $choices = ['moderation_status' => ['approved', 'reviewing', 'restricted', 'removed']];
        $filters = ListFilters::read($request, $choices + ['owner_id' => []]);
        $query = DB::table('comments')->select(['id', 'body', 'user_id', 'video_id', 'moderation_status', 'created_at']);
        if ($filters['moderation_status']) {
            $query->where('moderation_status', $filters['moderation_status']);
        }
        if ($filters['owner_id']) {
            $query->where('user_id', $filters['owner_id']);
        }
        if ($filters['q'] !== '') {
            $query->where(fn ($query) => $query->whereContains('body', $filters['q'])->orWhere('id', ctype_digit($filters['q']) ? (int) $filters['q'] : 0));
        }
        return Inertia::render('content/comments/index', Records::listing($query, $filters,
            fn ($row) => Records::row($row, mb_substr($row->body, 0, 160), (string) route('staff.comments.show', ['comment' => $row->id]), $row->moderation_status, 'Author #' . $row->user_id),
            'staff.comments.index', $choices) + ['page' => ['title' => 'Comments', 'breadcrumbs' => []], 'description' => 'Review comments and their conversation context.']);
    }

    public function show(Request $request, int $comment): Response
    {
        $row = DB::table('comments')->where('id', $comment)->firstOrFail();
        $context = Context::from($request);
        $fields = Records::fields($row, ['id', 'body', 'user_id', 'video_id', 'parent_id', 'moderation_status', 'created_at']);
        $links = [
            ['label' => 'Author #' . $row->user_id, 'url' => (string) route('staff.users.show', ['user' => $row->user_id])],
            ['label' => 'Video #' . $row->video_id, 'url' => (string) route('staff.videos.show', ['video' => $row->video_id])],
        ];
        if ($row->parent_id) {
            $parent = DB::table('comments')->where('id', $row->parent_id)->first();
            $fields[] = ['label' => 'Parent comment', 'value' => $parent?->body];
            $links[] = ['label' => 'Parent comment', 'url' => (string) route('staff.comments.show', ['comment' => $row->parent_id])];
        }
        $filters = ListFilters::read($request);
        $replies = DB::table('comments')->where('parent_id', $comment)->orderBy('id')->paginate($filters['per_page'], 'page');
        return Inertia::render('content/comments/show', ['page' => ['title' => 'Comment #' . $comment, 'breadcrumbs' => []],
            'fields' => $fields, 'links' => $links, 'moderation' => ModerationActions::forTarget($context, 'comment', $comment),
            'items' => ['rows' => array_map(fn ($reply) => ['label' => $reply->body, 'url' => (string) route('staff.comments.show', ['comment' => $reply->id])], $replies->items()),
                'pagination' => $replies->paginationMeta(), 'per_page' => $filters['per_page'], 'url' => (string) route('staff.comments.show', ['comment' => $comment])],
        ]);
    }
}
