<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommunicationResource;
use App\Services\{Communications, Conversations};
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

class ConversationController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $request->user('id');
        $result = Communications::page(Conversations::inboxQuery($userId), $request,
            fn($row) => CommunicationResource::conversation($row, $userId), [Conversations::class, 'hydrate']);
        $result['meta']['unread_count'] = DB::table('messages as m')
            ->join('conversation_participants as p', 'p.conversation_id', '=', 'm.conversation_id')
            ->where('p.user_id', $userId)->where('m.user_id', '!=', $userId)
            ->whereColumn('m.id', '>', 'p.last_read_message_id')->where('m.moderation_status', 'approved')->count();

        return $this->privateJson($result);
    }

    public function store(Request $request): Response
    {
        $input = Communications::input($request, ['recipient_user_id' => ['required', 'integer', 'min:1']]);
        $row = Conversations::open($request->user('id'), (int) $input['recipient_user_id']);

        return $this->privateJson(['data' => CommunicationResource::conversation(Conversations::state($row->id, $request->user('id')), $request->user('id'))]);
    }

    public function show(Request $request, int $conversation): Response
    {
        Conversations::participant($conversation, $request->user('id'));

        return $this->privateJson(['data' => CommunicationResource::conversation(Conversations::state($conversation, $request->user('id')), $request->user('id'))]);
    }

    public function messages(Request $request, int $conversation): Response
    {
        Conversations::participant($conversation, $request->user('id'));
        $input = $request->validate([
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'after_id' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        abort_if($input->has('before_id') && $input->has('after_id'), 422, 'Use either before_id or after_id.');
        $query = DB::table('messages')->where('conversation_id', $conversation);
        $after = $input->has('after_id');
        if ($after) {
            $query->where('id', '>', (int) $input->after_id);
        } elseif ($input->has('before_id')) {
            $query->where('id', '<', (int) $input->before_id);
        }
        $limit = (int) ($input->limit ?? 20);
        $rows = $query->orderBy('id', $after ? 'ASC' : 'DESC')->limit($limit + 1)->get()->all();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows ? (int) end($rows)->id : null;

        return $this->privateJson([
            'data' => array_map([CommunicationResource::class, 'message'], $rows),
            'meta' => ['limit' => $limit, 'has_more' => $more, 'next_before_id' => !$after && $more ? $last : null, 'next_after_id' => $after ? ($last ?? (int) $input->after_id) : null],
        ]);
    }

    public function send(Request $request, int $conversation): Response
    {
        $input = Communications::input($request, [
            'body' => ['required', 'string', 'max:' . config('communications.message_characters')],
            'client_message_id' => ['required', 'string', 'max:36'],
        ], text: ['body']);
        abort_unless(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $input['client_message_id']), 422, 'Supply a client-generated UUID.');
        [$row, $created] = Conversations::send($conversation, $request->user('id'), $input['body'], $input['client_message_id']);

        return $this->privateJson(['data' => CommunicationResource::message($row)], $created ? Response::STATUS_CREATED : Response::STATUS_OK);
    }

    public function read(Request $request, int $conversation): Response
    {
        $input = Communications::input($request, ['last_read_message_id' => ['required', 'integer', 'min:1']]);
        $row = Conversations::read($conversation, $request->user('id'), (int) $input['last_read_message_id']);

        return $this->privateJson(['data' => CommunicationResource::conversation(Conversations::state($row->id, $request->user('id')), $request->user('id'))]);
    }

    public function preferences(Request $request, int $conversation): Response
    {
        $input = Communications::input($request, ['muted' => ['required', 'boolean']], ['muted']);
        $row = Conversations::mute($conversation, $request->user('id'), $input['muted']);

        return $this->privateJson(['data' => CommunicationResource::conversation(Conversations::state($row->id, $request->user('id')), $request->user('id'))]);
    }
}
