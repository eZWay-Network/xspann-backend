<?php

namespace App\Http;

/** Request and response examples for the Step 1 account and operator API. */
class FoundationApiReference
{
    public static function catalog(): array
    {
        $result = [];
        $add = function ($action, $group, $title, $description, $response, $status = '200', $rules = [], $example = [], $limit = null, $errors = []) use (&$result): void {
            $result[$action] = compact('group', 'title', 'description', 'response', 'status', 'rules', 'example', 'limit', 'errors');
        };
        $requiredPolicies = array_map(fn($document) => $document['version'], array_filter(config('policies.documents'), fn($document) => $document['required']));
        $proof = ['proof' => ['required', 'string', 'max:128']];
        $version = ['version' => ['required', 'integer', 'min:1']];
        $reason = ['reason' => ['required', 'string', 'min:3', 'max:2000']];
        $conflict = ['409' => 'Already reviewed, duplicate appeal, or stale version. Reload before retrying.'];

        $add('AccountController@policies', 'Account safety', 'Read current policy versions', 'Public policy URLs and version identifiers; accept required versions during signup. Marketing is optional. The pilot minimum age is 18. Rights confirmation is a declaration, not copyright clearance.', [
            'minimum_age' => config('policies.minimum_age'),
            'policies' => config('policies.documents'),
            'rights_version' => config('policies.rights_version'),
            'rights_declaration' => 'I own or have permission for the video, audio, music and people shown, including any reuse I enable. I understand that selecting audio does not establish copyright clearance.',
        ]);
        $add(
            'AccountController@onboarding',
            'Account safety',
            'Complete social enrollment',
            'Use the opaque onboarding_token from social sign-in, not a bearer access token. It expires after ten minutes and is single-use. Requires an adult date_of_birth and all current required policies. Returns a normal session only after successful enrollment; invalid age/policies do not consume the token.',
            ['user' => '$AccountProfile', 'restricted_access' => false, 'token' => '<access-token>', 'token_expires_at' => '2026-11-28T12:00:00Z'],
            '200',
            ['onboarding_token' => ['required', 'string', 'max:128'], 'date_of_birth' => ['required', 'string'], 'accepted_policies' => ['required', 'array']],
            ['onboarding_token' => '<signup-token>', 'date_of_birth' => '1990-01-01', 'accepted_policies' => $requiredPolicies]
        );
        $add(
            'AccountController@acceptPolicies',
            'Account safety',
            'Record policy acceptance',
            'Available to verified restricted accounts too. Supply known policy names and their current versions. Acceptance is idempotent; the original timestamp is retained. This does not enroll users in artist messaging or authorize marketing delivery.',
            ['message' => 'Policy acceptance recorded.'],
            '200',
            ['accepted_policies' => ['required', 'array']],
            ['accepted_policies' => $requiredPolicies]
        );
        $add(
            'AccountController@reauthenticate',
            'Account safety',
            'Confirm a sensitive action',
            'Use exactly one credential: password or a Google ID token issued within five minutes for the already linked identity. Enabled staff must also supply a fresh six-digit TOTP. Proof expires in five minutes, is bound to the current bearer session and purpose, and can be consumed once. purpose=staff additionally grants that bearer session fifteen minutes of operator access. Suspended verified users may request privacy proofs.',
            ['proof' => '<single-use-proof>', 'purpose' => 'export_data', 'expires_at' => '2026-10-29T12:05:00Z', 'staff_access_expires_at' => null],
            '200',
            ['purpose' => ['required', ['in' => ['delete_account', 'export_data', 'staff']]], 'password' => ['nullable', 'string', 'max:1024'], 'google_token' => ['nullable', 'string', 'max:8192'], 'mfa_code' => ['nullable', 'string', 'max:6']],
            ['purpose' => 'export_data', 'password' => '<password>']
        );
        $add(
            'PrivacyController@deleteAccount',
            'Privacy',
            'Request account deletion',
            'Consumes a delete_account proof. Immediately hides the account and revokes all access and staff sessions. A durable background request removes media and personal records with retries. Retained report/audit evidence follows the documented retention policy. Deletion is not reversible.',
            ['reference' => '<request-reference>', 'status' => 'pending'],
            '202',
            $proof,
            ['proof' => '<delete-account-proof>']
        );
        $add(
            'PrivacyController@requestDeletionEmail',
            'Privacy',
            'Request deletion by email',
            'Public recovery path for users who cannot access their account. Always returns the same 202 response for valid email input. Sends an expiring confirmation only to the registered address. Requesting a link does not delete anything.',
            ['message' => 'If the account is eligible, a confirmation email will be sent.'],
            '202',
            ['email' => ['required', 'email', 'max:255']],
            ['email' => 'alex@example.com']
        );
        $add(
            'PrivacyController@confirmDeletion',
            'Privacy',
            'Confirm emailed deletion request',
            'POST the token from the emailed link after an explicit confirmation in the web UI. Token is valid for one hour and one use. This invokes the same deletion workflow as authenticated requests; GET links never delete accounts.',
            ['reference' => '<request-reference>', 'status' => 'pending'],
            '202',
            ['token' => ['required', 'string', 'max:128']],
            ['token' => '<email-token>']
        );
        $add(
            'PrivacyController@export',
            'Privacy',
            'Request your data export',
            'Consumes an export_data proof. At most one export is pending per account; another valid proof returns that pending request. Export includes own profile, content metadata, activity and policy/report records, excluding credentials, staff secrets and other users private evidence. Media files are not bundled.',
            ['id' => 1, 'reference' => '<request-reference>', 'status' => 'pending'],
            '202',
            $proof,
            ['proof' => '<export-proof>']
        );
        $add(
            'PrivacyController@status',
            'Privacy',
            'Read your export status',
            'Owner only, including suspended verified accounts. States: pending, processing, ready, failed, expired. A ready export is encrypted in private storage, retained for 24 hours, and has a five-minute signed download URL that still requires the same owner to authenticate.',
            ['id' => 1, 'reference' => '<request-reference>', 'status' => 'ready', 'download_url' => '/api/v1/me/export-requests/1/download?expires=<unix-time>&signature=<signature>', 'expires_at' => '2026-10-30T12:00:00Z']
        );
        $add(
            'PrivacyController@download',
            'Privacy',
            'Download your private data',
            'Use the exact download_url from the status response and send your bearer token. Returns an application/json attachment with Cache-Control: no-store. Another account, an altered signature or an expired link cannot download it. The response body is the export itself, without a data envelope.',
            ['generated_at' => '2026-10-29T12:00:00Z', 'account' => ['id' => 1, 'username' => 'alex'], 'date_of_birth' => '1990-01-01', 'policy_acceptances' => [], 'videos' => []],
            '200',
            ['expires' => ['required', 'integer'], 'signature' => ['required', 'string', 'max:64']]
        );
        $add(
            'AudioController@update',
            'Audio library',
            'Update your sound',
            'Owner may change title and explicit reuse_allowed only. Source, creator and file cannot be replaced. Original-sound permission is synchronized with its source video. Withdrawal stops new reuse; existing rendered clips retain their embedded soundtrack.',
            '$Audio',
            '200',
            ['title' => ['sometimes', 'required', 'string', 'max:120'], 'reuse_allowed' => ['sometimes', 'required', 'boolean']],
            ['reuse_allowed' => false]
        );
        $add('AudioController@destroy', 'Audio library', 'Remove your sound', 'Owner only. Stops new selection immediately and queues idempotent file cleanup. Existing rendered videos keep embedded audio; their audio_id is cleared when cleanup removes the audio row. Rights restrictions use moderation and propagate separately.', ['message' => 'Audio deleted'], '202');
        $report = ['id' => 1, 'target_type' => 'video', 'target_id' => 2, 'reason' => 'Copyright concern', 'status' => 'open', 'version' => 1, 'created_at' => '2026-10-29T12:00:00Z'];
        $add('ModerationController@reports', 'Moderation', 'Review report queue', 'Staff role plus recent MFA required. Optional status=open|resolved and type=user|video|audio|comment filters. Bounded pagination, oldest first. Queue access is audited. No evidence is included in the listing.', $report, '200', [], [], 20);
        $add(
            'ModerationController@report',
            'Moderation',
            'Read reported evidence',
            'Staff role plus recent MFA required; every evidence read is audited. The encrypted snapshot contains only reported public content metadata and a media reference, not account credentials. The snapshot survives target deletion; the original media file may no longer exist.',
            ['id' => 1, 'target_type' => 'video', 'target_id' => 2, 'reason' => 'Copyright concern', 'details' => 'Please review', 'status' => 'open', 'version' => 1, 'evidence' => ['type' => 'video', 'id' => 2, 'caption' => 'Reported content'], 'decisions' => []]
        );
        $add(
            'ModerationController@decide',
            'Moderation',
            'Resolve a report',
            'Staff with recent MFA; cannot review reports they filed or content they own. action=dismiss closes without restriction; restrict hides the target and affected audio/video/comment derivatives. action=restore releases this report’s active restriction after later review, unless an appeal is open (use its independent reviewer). Requires the displayed version. Records an immutable reason and decision, notifies the subject and resolves the report atomically.',
            ['decision_id' => 1, 'status' => 'resolved', 'version' => 2],
            '200',
            ['action' => ['required', ['in' => ['dismiss', 'restrict', 'restore']]]] + $reason + $version,
            ['action' => 'restrict', 'reason' => 'Rights could not be confirmed.', 'version' => 1],
            null,
            $conflict
        );
        $add('ModerationController@ownReport', 'Moderation', 'Read your report status', 'Only the reporter can read this status. No other private evidence, reviewer identity or internal notes are returned. Available to verified restricted accounts.', ['id' => 1, 'status' => 'resolved', 'version' => 2]);
        $add(
            'ModerationController@ownDecisions',
            'Moderation',
            'Read decisions about your account',
            'Read your own decision identifiers, action, reason and active status, including while suspended. Use an active restriction decision ID to submit an appeal. No reporter identity or evidence is included.',
            ['id' => 1, 'action' => 'restrict', 'reason' => 'Rights could not be confirmed.', 'active' => true, 'created_at' => '2026-10-29T12:00:00Z'],
            '200',
            [],
            [],
            20
        );
        $add(
            'ModerationController@appeal',
            'Moderation',
            'Appeal your restriction',
            'Available to the subject of an active restriction, including suspended verified accounts. One appeal per decision. Supply a concise explanation; an independent staff reviewer decides it. Another user cannot appeal your decision.',
            ['id' => 1, 'status' => 'open', 'version' => 1],
            '201',
            ['decision_id' => ['required', 'integer', 'min:1'], 'explanation' => ['required', 'string', 'min:3', 'max:4000']],
            ['decision_id' => 1, 'explanation' => 'I can provide the license for this recording.'],
            null,
            $conflict
        );
        $appeal = ['id' => 1, 'decision_id' => 1, 'explanation' => 'Please review my license.', 'status' => 'open', 'resolution' => null, 'version' => 1, 'created_at' => '2026-10-29T12:00:00Z', 'updated_at' => '2026-10-29T12:00:00Z'];
        $add('ModerationController@ownAppeals', 'Moderation', 'Read your appeal outcomes', 'Only your own appeals, explanations and decisions. Available to restricted verified accounts. States are open, upheld and overturned; pagination is bounded.', $appeal, '200', [], [], 20);
        $add('ModerationController@appeals', 'Moderation', 'Review appeal queue', 'Staff role plus recent MFA required. status=open by default; also accepts upheld or overturned. Includes explanation, resolution and report_id for opening the original evidence/decision history; listing access is audited.', $appeal + ['user_id' => 1, 'reviewer_id' => null, 'report_id' => 1], '200', [], [], 20);
        $add(
            'ModerationController@decideAppeal',
            'Moderation',
            'Decide an appeal',
            'Requires recent staff MFA, a reason and the displayed version. The reviewer must differ from the original decision maker, reporter and subject. Overturn removes only restrictions from this decision; independent restrictions and account deletion remain effective.',
            ['id' => 1, 'outcome' => 'overturn', 'version' => 2],
            '200',
            ['outcome' => ['required', ['in' => ['uphold', 'overturn']]]] + $reason + $version,
            ['outcome' => 'overturn', 'reason' => 'The submitted license permits this use.', 'version' => 1],
            null,
            $conflict
        );

        return $result;
    }
}
