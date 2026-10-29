<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendDeletionConfirmation;
use App\Services\{AuditTrail, PrivacyRequests};
use Spark\Facades\Hash;
use Spark\Http\{Request, Response};

class PrivacyController extends Controller
{
    public function deleteAccount(Request $request): Response
    {
        $input = $request->validate(['proof' => ['required', 'string', 'max:128']]);
        $record = PrivacyRequests::request($request->user(), 'deletion', $input->proof);

        return json(['data' => ['reference' => $record->reference, 'status' => $record->status]], 202);
    }

    public function requestDeletionEmail(Request $request): Response
    {
        $input = $request->validate(['email' => ['required', 'email', 'max:255']]);
        SendDeletionConfirmation::dispatch(Hash::encrypt($input->email()))->onQueue('privacy')->send();

        return json(['data' => ['message' => 'If the account is eligible, a confirmation email will be sent.']], 202);
    }

    public function confirmDeletion(Request $request): Response
    {
        $input = $request->validate(['token' => ['required', 'string', 'max:128']]);
        $record = PrivacyRequests::confirmDeletion($input->token);

        return json(['data' => ['reference' => $record->reference, 'status' => $record->status]], 202);
    }

    public function export(Request $request): Response
    {
        $input = $request->validate(['proof' => ['required', 'string', 'max:128']]);
        $record = PrivacyRequests::request($request->user(), 'export', $input->proof);

        return json(['data' => ['id' => $record->id, 'reference' => $record->reference, 'status' => $record->status]], 202);
    }

    private function owned(Request $request, int $export): object
    {
        $record = query('privacy_requests')->where('id', $export)->where('kind', 'export')->where('user_id', $request->user('id'))->first();
        abort_unless($record !== null, 404, 'Export not found.');
        if ($record->status === 'ready' && strtotime($record->expires_at) <= time()) {
            PrivacyRequests::removeExport($export);
            $record->status = 'expired';
        }

        return $record;
    }

    public function status(Request $request, int $export): Response
    {
        $record = $this->owned($request, $export);
        $expires = min(time() + 300, $record->expires_at ? strtotime($record->expires_at) : 0);
        $url = $record->status === 'ready' ? home_url("/api/v1/me/export-requests/$export/download?") . http_build_query([
            'expires' => $expires,
            'signature' => PrivacyRequests::signature($export, $request->user('id'), $expires),
        ]) : null;

        return json(['data' => ['id' => $export, 'reference' => $record->reference, 'status' => $record->status, 'download_url' => $url, 'expires_at' => $record->expires_at ? \Spark\Carbon::parse($record->expires_at) : null]])
            ->setHeader('Cache-Control', 'no-store');
    }

    public function download(Request $request, int $export): Response
    {
        $input = $request->validate(['expires' => ['required', 'integer'], 'signature' => ['required', 'string', 'max:64']]);
        $record = $this->owned($request, $export);

        abort_unless(
            $record->status === 'ready' && (int) $input->expires >= time() && (int) $input->expires <= time() + 300
            && hash_equals(PrivacyRequests::signature($export, $request->user('id'), (int) $input->expires), $input->signature),
            403,
            'This download link is invalid or expired.'
        );

        $contents = Hash::decrypt(
            storage('local')->get(PrivacyRequests::exportPath($export))
        );

        AuditTrail::record('privacy.export.downloaded', ['request_id' => $export], $request->user('id'));

        return response($contents, 200, ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="xpn-social-data.json"', 'Cache-Control' => 'no-store']);
    }
}
