<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommunicationResource;
use App\Services\{Announcements, Artists, Communications, NotificationReads};
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

class AnnouncementController extends Controller
{
    public static function rules(bool $editing = false): array
    {
        return [
            'title' => [$editing ? 'sometimes' : 'required', 'string', 'max:120'],
            'body' => [$editing ? 'sometimes' : 'required', 'string', 'max:2000'],
            'video_id' => ['nullable', 'integer', 'min:1'],
            ...($editing ? ['version' => ['required', 'integer', 'min:1']] : []),
        ];
    }

    public function index(Request $request): Response
    {
        $artist = Artists::owned($request->user());

        return $this->privateJson(Communications::page(DB::table('announcements')->where('artist_id', $artist->id), $request,
            fn($row) => CommunicationResource::announcement($row, true), [Announcements::class, 'summaries']));
    }

    public function store(Request $request): Response
    {
        $input = Communications::input($request, self::rules(), text: ['title', 'body']);

        return $this->privateJson(['data' => CommunicationResource::announcement(Announcements::summaries([Announcements::create($request->user(), $input)])[0], true)], Response::STATUS_CREATED);
    }

    public function show(Request $request, int $announcement): Response
    {
        return $this->privateJson(['data' => CommunicationResource::announcement(Announcements::summaries([Announcements::owned($announcement, $request->user('id'))])[0], true)]);
    }

    public function update(Request $request, int $announcement): Response
    {
        $input = Communications::input($request, self::rules(true), text: ['title', 'body']);

        return $this->privateJson(['data' => CommunicationResource::announcement(Announcements::summaries([Announcements::edit($request->user(), $announcement, $input)])[0], true)]);
    }

    public function destroy(Request $request, int $announcement): Response
    {
        Announcements::delete($announcement, $request->user('id'));

        return response('', Response::STATUS_NO_CONTENT);
    }

    public function send(Request $request, int $announcement): Response
    {
        $input = Communications::input($request, ['version' => ['required', 'integer', 'min:1']]);
        $result = Announcements::send($request->user(), $announcement, (int) $input['version'], (string) $request->header('Idempotency-Key', ''));

        return $this->privateJson(['data' => $result], Response::STATUS_ACCEPTED);
    }

    public function inbox(Request $request): Response
    {
        $viewer = $request->user();
        $query = Announcements::inboxQuery($viewer);

        return $this->privateJson(Communications::page($query, $request, fn($row) => CommunicationResource::announcement($row, false, $row->delivery),
            fn($rows) => Announcements::deliveries($rows, $viewer->id)));
    }

    public function received(Request $request, int $announcement): Response
    {
        return $this->privateJson(['data' => $this->recipientData(Announcements::delivered($announcement, $request->user('id')), $request->user('id'))]);
    }

    public function read(Request $request, int $announcement): Response
    {
        Communications::input($request, []);
        NotificationReads::announcement($request->user('id'), $announcement);

        return $this->received($request, $announcement);
    }

    private function recipientData(object $row, int $viewer): array
    {
        return CommunicationResource::announcement($row, false, DB::table('announcement_deliveries')
            ->where('announcement_id', $row->id)->where('user_id', $viewer)->first());
    }
}
