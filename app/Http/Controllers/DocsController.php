<?php

namespace App\Http\Controllers;

use Spark\Http\Response;

use App\Http\Requests\{Auth\RegisterRequest, Auth\LoginRequest, Auth\ProfileUpdateRequest, Videos\StoreVideoRequest, Videos\UpdateVideoRequest, Comments\StoreCommentRequest, Shares\StoreShareRequest, Uploads\AvatarUploadRequest, Uploads\LocalAudioUploadRequest, Uploads\LocalVideoUploadRequest, Uploads\SignedVideoUploadRequest};
use App\Services\ChunkUploads;


class DocsController extends Controller
{
    public function __invoke(): Response
    {
        $catalog = self::catalog();
        $schemas = self::schemas();
        $groups = array_fill_keys(['Authentication', 'Feed', 'Discover', 'Videos', 'Uploads', 'Users', 'Comments', 'Social actions', 'Safety', 'Notifications', 'Audio library'], []);
        foreach (router()->getRoutes() as $route) {
            if (!str_starts_with($route['path'], '/api/v1/')) {
                continue;
            }
            [$controller, $action] = $route['callback'];
            $key = substr($controller, strrpos($controller, '\\') + 1) . '@' . $action;
            if (!isset($catalog[$key])) {
                throw new \LogicException('Missing API documentation: ' . $key);
            }
            $entry = $catalog[$key];
            $entry['method'] = $route['method'];
            $entry['uri'] = preg_replace('/\{user:username\}/', '{username}', $route['path']);
            $entry['route'] = $route['path'];
            $entry['id'] = strtolower(str_replace('@', '-', $key));
            $entry['auth'] = in_array('auth', $route['middleware'], true);
            $entry['rate'] = null;
            foreach ($route['middleware'] as $middleware) {
                if (str_starts_with($middleware, 'throttle:')) {
                    $entry['rate'] = (int) substr($middleware, 9);
                }
            }
            $entry['parameters'] = [];
            preg_match_all('/\{([^}]+)\}/', $entry['uri'], $matches);
            foreach ($matches[1] as $name) {
                $description = match ($name) {
                    'username' => 'Account username (not its numeric ID).',
                    'provider' => 'Only google is supported.',
                    'hash' => 'Email SHA-1 hash from the verification link.',
                    default => 'Numeric ' . $name . ' ID.',
                };
                $entry['parameters'][] = ['name' => $name, 'in' => 'path', 'type' => in_array($name, ['username', 'provider', 'hash']) ? 'string' : 'integer', 'required' => true, 'description' => $description];
            }
            if ($entry['limit'] !== null) {
                $entry['parameters'][] = ['name' => 'page', 'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => 'Page number; default 1.'];
                $entry['parameters'][] = ['name' => 'limit', 'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => 'Items per page; default ' . $entry['limit'] . '. Clamped to 1–100.'];
            }
            if (str_starts_with($key, 'DiscoverController@')) {
                $entry['parameters'] = [
                    ['name' => 'q', 'in' => 'query', 'type' => 'string', 'required' => false, 'description' => 'Optional search text, at most 100 characters. Surrounding whitespace is ignored. SQL wildcard characters are literal. Omit or clear to browse.'],
                    ['name' => 'page', 'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => 'Page number, 1–100000; default 1.'],
                    ['name' => 'limit', 'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => 'Items per page, 1–100; default 18. Invalid values return 422.'],
                ];

                if ($action === 'index') {
                    $entry['parameters'][] = [
                        'name' => 'sort',
                        'in' => 'query',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'popular (default) or latest. Popular sorts by total likes/comments/saves/shares, then views, creation time and ID descending. Latest sorts by creation time and ID descending.',
                    ];
                }

                $entry['errors']['422'] = 'Invalid query, page, limit or sort.';
            }
            if ($key === 'AudioController@index') {
                $entry['parameters'][] = ['name' => 'q', 'in' => 'query', 'type' => 'string', 'required' => false, 'description' => 'Search sound titles, up to 100 characters.'];
                $entry['errors']['422'] = 'Invalid search query.';
            }
            if ($key === 'NotificationController@index') {
                $entry['parameters'][] = [
                    'name' => 'unread',
                    'in' => 'query',
                    'type' => 'boolean',
                    'required' => false,
                    'description' => 'Use 1 to return only unread notifications; default 0 returns all. meta.unread_count always counts all unread notifications for this account, independent of pagination.',
                ];
                $entry['errors']['422'] = 'Invalid unread filter.';
            }
            if ($action === 'verifyEmail') {
                foreach (['expires' => 'Unix timestamp from the signed link.', 'signature' => 'HMAC from the signed link. Preserve every query parameter exactly.'] as $name => $description) {
                    $entry['parameters'][] = ['name' => $name, 'in' => 'query', 'type' => $name === 'expires' ? 'integer' : 'string', 'required' => true, 'description' => $description];
                }
            }
            $body = self::parameters($entry['rules']);
            $entry['parameters'] = array_merge($entry['parameters'], $body);
            $entry['contentType'] = array_filter($body, fn($p) => $p['type'] === 'file') ? 'multipart/form-data' : ($body ? 'application/json' : null);
            $response = self::expand($entry['response'], $schemas);
            $entry['response'] = ['data' => $entry['limit'] ? [$response] : $response];
            if ($entry['limit']) {
                $pageUrl = url($entry['uri']);
                $entry['response']['links'] = ['first' => $pageUrl . '?page=1', 'last' => $pageUrl . '?page=1', 'prev' => null, 'next' => null];
                $entry['response']['meta'] = ['current_page' => 1, 'per_page' => $entry['limit'], 'last_page' => 1, 'total' => 1, 'from' => 1, 'to' => 1];
            }
            if ($key === 'NotificationController@index') {
                $entry['response']['meta']['unread_count'] = 1;
            }
            if ($key === 'AuthController@register') {
                $entry['response']['data']['user']['email_verified_at'] = null;
                $entry['response']['data']['user']['videos_count'] = 0;
            }
            if ($key === 'CommentReactionController@store') {
                $entry['response']['data']['viewer_reaction'] = 'love';
                $entry['response']['data']['reactions']['love'] = 1;
            }
            if ($key === 'UploadController@video') {
                $entry['alternate'] = ['data' => ['upload_method' => 'multipart', 'upload_url' => url('/api/v1/uploads/videos/local'), 'storage_path' => null, 'headers' => [], 'field_name' => 'file', 'message' => 'Upload the video through the backend multipart endpoint.']];
            }
            if ($entry['auth']) {
                $entry['errors'] += ['401' => 'Missing, expired, revoked or invalid bearer token.', '403' => 'Inactive/unverified account, or an owner-only operation denied.'];
            }
            if ($matches[1]) {
                $entry['errors'] += ['404' => 'Resource not found or not visible to this viewer.'];
            }
            if ($body) {
                $entry['errors'] += ['422' => 'Invalid parameters or a business validation failure.'];
            }
            if ($entry['rate']) {
                $entry['errors']['429'] = 'Too many requests. Back off before retrying.';
            }
            unset($entry['rules']);
            $groups[$entry['group']][] = $entry;
        }
        return view('docs', ['groups' => $groups, 'schemas' => $schemas, 'baseUrl' => url('/api/v1'), 'endpointCount' => array_sum(array_map('count', $groups))]);
    }

    public static function catalog(): array
    {
        $result = [];
        $add = function ($action, $group, $title, $description, $response, $status = '200', $rules = [], $example = [], $limit = null, $errors = []) use (&$result) {
            $result[$action] = compact('group', 'title', 'description', 'response', 'status', 'rules', 'example', 'limit', 'errors');
        };
        $email = ['email' => ['required', 'email']];
        $password = ['password' => ['required', 'string', 'min:8', 'confirmed']];
        $message = fn($text) => ['message' => $text];
        $add('AuthController@register', 'Authentication', 'Create an account', 'Creates an account and queues a verification email. No access token is issued until the account is verified and signs in. Managed avatar URLs must belong to the newly created account; normally upload an avatar after login.', ['user' => '$Profile', 'token' => null, 'token_expires_at' => null, 'message' => 'We have sent you an email to verify your account. Please check your inbox or spam folder.'], '201', RegisterRequest::class, ['username' => 'alex', 'email' => 'alex@example.com', 'password' => 'example-password', 'password_confirmation' => 'example-password']);
        $session = ['user' => '$Profile', 'token' => '<access-token>', 'token_expires_at' => '2026-10-01T12:00:00.000000Z'];
        $add('AuthController@login', 'Authentication', 'Sign in with email', 'Returns a JWT for an active, verified account. device_name is accepted but is not used to label tokens.', $session, '200', LoginRequest::class, ['email' => 'alex@example.com', 'password' => 'example-password'], null, ['422' => 'Incorrect credentials or invalid input.', '403' => 'Inactive account or unverified email.']);
        $add('AuthController@social', 'Authentication', 'Sign in with Google', 'Only provider=google is supported. Send a Google ID token (credential), not an OAuth access token. New accounts are verified. Gmail and Google Workspace identities can link to matching emails automatically; other email matches require password login.', $session, '200', ['token' => ['required', 'string', 'max:8192']], ['token' => '<google-id-token>'], null, ['401' => 'Invalid Google credential.', '403' => 'Inactive account.', '404' => 'Unsupported provider.', '409' => 'Email match requires password login.', '503' => 'Google configuration or signing-key service unavailable.']);
        $add('AuthController@forgotPassword', 'Authentication', 'Request a password reset', 'Queues a reset link when the email exists. The successful response does not reveal account existence. A repeat request within 60 seconds can return 422. Read the token and email from the reset link.', $message('If that email exists, a password reset link has been sent.'), '200', $email, ['email' => 'alex@example.com']);
        $add('AuthController@resetPassword', 'Authentication', 'Reset a password', 'Consumes a valid, unexpired reset token and revokes all existing access tokens. Sign in again afterward. Token lifetime is configured by the server (60 minutes by default).', $message('Password reset successfully. Please log in again.'), '200', $email + ['token' => ['required', 'string']] + $password, ['email' => 'alex@example.com', 'token' => '<email-reset-token>', 'password' => 'new-example-password', 'password_confirmation' => 'new-example-password']);
        $add('AuthController@verifyEmail', 'Authentication', 'Verify an email address', 'Open the exact signed URL received by email, preserving its origin, path and query string. Do not construct the signature in the frontend. Verification is idempotent and does not issue a token.', $message('Email verified successfully.'), '200', [], [], null, ['403' => 'Invalid or expired signature, or email hash mismatch.', '404' => 'Account not found.']);
        $add('AuthController@resendVerification', 'Authentication', 'Resend verification email', 'Queues email only for an active, unverified account. Returns the same message when no verification is needed.', $message('If verification is needed, an email has been sent.'), '200', $email, ['email' => 'alex@example.com']);
        $add('AuthController@logout', 'Authentication', 'Revoke the current token', 'Invalidates only the bearer token used for this request. Discard it in the client.', $message('Logged out'));
        $add('AuthController@me', 'Authentication', 'Get the signed-in profile', 'Returns your profile, including social_identities. Other profile responses omit social_identities because the relation is not loaded.', '$MyProfile');
        $add('AuthController@update', 'Authentication', 'Update your profile', 'Updates only submitted fields. Username must be unique. Nullable profile fields can be cleared with null. Managed avatar URLs must belong to you.', '$Profile', '200', ProfileUpdateRequest::class, ['name' => 'Alex Morgan', 'bio' => 'Making something new.']);
        $add('AuthController@changePassword', 'Authentication', 'Change your password', 'Requires the existing password. Revokes other access tokens while preserving the current token.', $message('Password changed successfully.'), '200', ['current_password' => ['required', 'string']] + $password, ['current_password' => 'example-password', 'password' => 'new-example-password', 'password_confirmation' => 'new-example-password']);
        $add('AuthController@refreshToken', 'Authentication', 'Rotate the access token', 'Requires a still-valid bearer token. Revokes that token and returns a replacement. Replace the stored token atomically; an expired token cannot be refreshed.', $session);
        foreach (['FeedController@index' => ['Feed', 'Get the home feed', 'Published videos visible to the viewer, newest first. Use GET /discover for video search and popularity sorting.'], 'FeedController@following' => ['Feed', 'Get the following feed', 'Published videos from accounts you follow, newest first.'], 'VideoController@index' => ['Videos', 'List published videos', 'Published, visible videos ordered newest first.'], 'VideoController@mine' => ['Videos', 'List your videos', 'Your non-deleted videos, including processing and failed uploads. Pinned videos appear first, then newest first.'], 'UserController@videos' => ['Users', 'List a user’s videos', 'Published videos from the requested username, filtered by viewer visibility.'], 'LikeController@index' => ['Social actions', 'List your liked videos', 'Visible, published videos ordered by when you liked them.'], 'SaveController@index' => ['Social actions', 'List your saved videos', 'Visible, published videos ordered by when you saved them.']] as $action => [$group, $title, $description]) {
            $add($action, $group, $title, $description, '$Video', '200', [], [], $action === 'VideoController@mine' ? 20 : 10);
        }
        $add(
            'DiscoverController@index',
            'Discover',
            'Discover and search videos',
            'Public, published videos from active, visible accounts. q matches a literal phrase in captions (including hashtags), creator usernames or names; @ prefixes are removed for creator matching. Private and followers-only videos are excluded even for their owner. Optional JWT adds viewer state and hides blocks in either direction. Popular is an all-time engagement ordering, not a trending or personalized recommendation algorithm. Search runs before pagination; ordering has a stable ID tie-break.',
            '$Video',
            '200',
            [],
            [],
            18,
        );
        $add(
            'DiscoverController@people',
            'Discover',
            'Discover and search people',
            'q partially matches usernames or display names; a leading @ is optional. Exact username matches rank first, then follower count, creation time and ID descending. With no search, suggests active accounts by follower count and recency; signed-in viewers exclude themselves and accounts they follow. Search includes matching followed accounts and self. Blocks in both directions and inactive accounts are always hidden from the viewer. Returns public User resources without email or credentials.',
            '$User',
            '200',
            [],
            [],
            18,
        );
        $add('VideoController@show', 'Videos', 'Get a video', 'Returns one visible video. Only its owner can retrieve processing or failed videos. Deleted or inaccessible videos return 404.', '$Video');
        $add('VideoController@store', 'Videos', 'Create a video post', 'First upload the media, then submit its storage_path. The file must exist and belong to you. Creates a processing video and queues H.264/AAC compression. Optional audio_id selects a ready library sound; audio_mode is replace (default) or mix. Muting removes the original audio. Without a library selection, audible original sound becomes a reusable track following source privacy. Poll GET /videos/{video} until published or failed. Managed thumbnail and sound URLs must also belong to you. Editing times are in seconds. Creation applies trim_start/trim_end, a centered portrait 9:16 crop (fill) or letterbox (fit), brightness/contrast/saturation, original muting and audio_settings (start in sound seconds, original_volume and sound_volume from 0 to 1). Library audio loops to cover the trimmed clip. cover_time is relative to the edited output. Cropping and filters are encoded by the worker; preset labels, warmth, cut_points, text_overlay and effect_settings remain metadata only. Invalid media ranges fail processing. Editing existing posts does not re-encode media.', '$ProcessingVideo', '201', StoreVideoRequest::class, ['storage_path' => 'videos/1/example.mp4', 'caption' => 'Hello #world', 'visibility' => 'public']);
        $add('VideoController@update', 'Videos', 'Update your video', 'Owner only (403 otherwise). Updates submitted metadata and edit settings; this endpoint does not dispatch a new processing job. pinned=true sets pinned_at; false clears it. effect_settings is accepted only during creation.', '$Video', '200', UpdateVideoRequest::class, ['caption' => 'An updated caption', 'pinned' => true]);
        $add('VideoController@destroy', 'Videos', 'Delete your video', 'Owner only (403 otherwise). Immediately marks the video deleted and queues storage cleanup.', $message('Video deleted'));
        foreach (['suggestions' => ['Suggested accounts', 'Accounts ranked by follower count, then recency. For signed-in viewers, excludes self, followed accounts and blocked relationships.', 18], 'followers' => ['List followers', 'Visible accounts following this username.', 20], 'following' => ['List followed accounts', 'Visible accounts this username follows.', 20]] as $action => [$title, $description, $limit]) {
            $add('UserController@' . $action, 'Users', $title, $description, '$User', '200', [], [], $limit);
        }
        $add('UserController@show', 'Users', 'Get a public profile', 'Looks up a username, not a numeric ID. Current Profile responses include email and email_verified_at as well as public profile counts. Blocked or inactive accounts are hidden.', '$Profile');
        $add('CommentController@index', 'Comments', 'List video comments', 'Top-level comments only, newest first. The video and comment authors must be visible to the viewer.', '$Comment', '200', [], [], 20);
        $add('CommentController@replies', 'Comments', 'List comment replies', 'Direct replies to a visible comment, newest first. The parent video must be visible.', '$Comment', '200', [], [], 20);
        $add('CommentController@store', 'Comments', 'Create a comment or reply', 'Add a comment to a published, visible video. To reply, pass parent_id belonging to a visible comment on the same video.', '$Comment', '201', StoreCommentRequest::class, ['body' => 'Love this!']);
        $add('CommentController@destroy', 'Comments', 'Delete your comment', 'Comment author only (403 otherwise). Deletes the comment and recalculates the video comment count.', $message('Comment deleted'));
        $add('CommentReactionController@store', 'Comments', 'Set a comment reaction', 'One reaction per account per comment. Replaces your previous reaction. Defaults to like when omitted.', '$Comment', '200', ['reaction_type' => ['sometimes', 'string', 'in:like,love,haha,wow,sad,angry']], ['reaction_type' => 'love']);
        $add('CommentReactionController@destroy', 'Comments', 'Remove your reaction', 'Removes your reaction and returns the comment with updated reaction counts.', '$Comment');
        foreach (['Like' => ['like', 'liked', 'likes'], 'Save' => ['save', 'saved', 'saves']] as $controller => [$verb, $state, $counter]) {
            $add($controller . 'Controller@store', 'Social actions', ucfirst($verb) . ' a video', 'Requires a published, visible video. Idempotent: first creation returns 201 with created=true; repeated requests return 200 with created=false.', [$state => true, $counter . '_count' => 1, 'created' => true], '201 / 200');
            $add($controller . 'Controller@destroy', 'Social actions', 'Remove a ' . $verb, 'Removes your own ' . $verb . '. Repeated requests do not decrement the count again.', [$state => false, $counter . '_count' => 0]);
        }
        $add('ShareController@store', 'Social actions', 'Record a share', 'Available to guests. Records each request and returns a frontend share URL; repeated requests increment shares_count.', ['video_id' => 1, 'share_url' => 'https://frontend.example.com/video/1', 'shares_count' => 1], '201', StoreShareRequest::class, ['channel' => 'copy_link']);
        $add('ViewController@store', 'Social actions', 'Record a video view', 'Available to guests. Deduplicates views for six hours by account, or by IP and user agent for guests. A new view returns 201; a duplicate returns 200.', ['viewed' => true, 'views_count' => 1], '201 / 200');
        foreach (['store' => true, 'destroy' => false] as $action => $state) {
            $add('FollowController@' . $action, 'Social actions', $state ? 'Follow an account' : 'Unfollow an account', $state ? 'Uses a numeric user ID. Cannot follow yourself (422) or an invisible account (404). Repeated follows do not create duplicates.' : 'Removes your follow relationship with the numeric user ID.', ['following' => $state, 'followers_count' => $state ? 1 : 0, 'following_count' => $state ? 1 : 0], $state ? '201' : '200');
            $add('BlockController@' . $action, 'Safety', $state ? 'Block an account' : 'Unblock an account', $state ? 'Cannot block yourself (422). Removes follow relationships in both directions. Blocked relationships are hidden from visibility-filtered profiles, videos and comments.' : 'Removes your block. Previously removed follows are not restored.', ['blocked' => $state], $state ? '201' : '200');
        }
        $add('BlockController@index', 'Safety', 'List blocked accounts', 'Accounts you have blocked, newest accounts first.', '$User', '200', [], [], 20);
        $add('ReportController@store', 'Safety', 'Report a video or account', 'Provide exactly one of video_id or reported_user_id. Videos must be visible. You cannot report yourself. Reports are created with status=open.', ['id' => 1, 'status' => 'open'], '201', ['video_id' => ['nullable', 'integer', 'min:1'], 'reported_user_id' => ['nullable', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:255'], 'details' => ['nullable', 'string', 'max:2000']], ['video_id' => 1, 'reason' => 'Spam', 'details' => 'Repeated promotional content.']);
        $add('AudioSaveController@index', 'Audio library', 'List your saved sounds', 'Available saved sounds for the signed-in account, newest saves first. Hidden or unavailable sounds are omitted; saving does not bypass source privacy.', '$Audio', '200', [], [], 20);
        $add('AudioSaveController@store', 'Audio library', 'Save a sound', 'Saves an available sound to your library. First save returns 201; repeated saves return 200 without duplicates.', ['saved' => true, 'created' => true], '201 / 200');
        $add('AudioSaveController@destroy', 'Audio library', 'Unsave a sound', 'Removes only your saved reference, even when the sound is no longer available. Repeated removals return the same response.', ['saved' => false]);
        $add('AudioController@store', 'Audio library', 'Create a sound', 'Submit the storage_path from the sound upload endpoint and a title. Creates a processing library entry and queues AAC conversion. Poll the sound profile until ready.', '$ProcessingAudio', '201', \App\Http\Requests\Audios\StoreAudioRequest::class, ['storage_path' => 'sounds/1/example.mp3', 'title' => 'My sound']);
        $add('AudioController@index', 'Audio library', 'Browse reusable sounds', 'Ready sounds from active, unblocked creators. Original sounds require a public, published source short. Search with q; results are newest first.', '$Audio', '200', [], [], 20);
        $add('AudioController@show', 'Audio library', 'Sound profile', 'Returns the sound and its original creator. Owners can poll processing or failed uploads; others receive 404 until a sound is available.', '$Audio');
        $add('AudioController@videos', 'Audio library', 'Shorts using a sound', 'Published shorts using this sound, filtered by viewer visibility. The sound must still be available for reuse.', '$Video', '200', [], [], 20);
        $add('NotificationController@index', 'Notifications', 'List your notifications', 'Stored in-app activity for the signed-in account, newest first. Supports unread filtering and includes meta.unread_count for the badge. See the Notification schema for event types, recipients and payload fields.', '$Notification', '200', [], [], 20);
        $add('NotificationController@read', 'Notifications', 'Mark a notification read', 'Only your own notifications can be read; another account’s notification returns 404. Repeated requests preserve the original read_at.', '$ReadNotification');
        $add('NotificationController@readAll', 'Notifications', 'Mark all notifications read', 'Marks all of your unread notifications as read.', $message('Notifications marked as read.'));
        $add(
            'NotificationController@clearAll',
            'Notifications',
            'Clear read notifications',
            'Permanently deletes only your read notifications. Unread notifications and other accounts’ notifications are preserved. Repeated requests or an empty inbox return the same successful response. No request body is required.',
            $message('Read notifications cleared.'),
        );
        foreach (['avatar' => [AvatarUploadRequest::class, 'Upload an avatar', 'JPEG, PNG or WebP image, up to 5 MiB. Use avatar_url in PUT /auth/profile.', 'avatar_url', 'avatars/1/example.webp'], 'local' => [LocalVideoUploadRequest::class, 'Upload a video file', 'MP4, QuickTime or WebM, up to 500 MiB. Uploads through the backend to the configured storage disk. Submit storage_path to POST /videos afterward.', 'video_url', 'videos/1/example.mp4'], 'audio' => [LocalAudioUploadRequest::class, 'Upload a sound', 'Audio file up to 50 MiB. Uploads only; submit storage_path and title to POST /audios afterward. Processing enforces a 600-second limit.', 'audio_url', 'sounds/1/example.mp3']] as $action => [$rules, $title, $description, $urlKey, $path]) {
            $add('UploadController@' . $action, 'Uploads', $title, $description . ' Send multipart/form-data with field file. Server and proxy upload limits may be lower.', ['upload_method' => 'multipart', 'storage_path' => $path, $urlKey => 'https://media.example.com/' . $path], '201', $rules, ['file' => '@/path/to/file']);
        }
        $add('UploadController@video', 'Uploads', 'Request a video upload URL', 'Inspect upload_method. signed_url: PUT the raw file to upload_url using exactly the returned headers within 15 minutes, without the API bearer token. multipart: POST FormData with field_name to upload_url with your bearer token. After upload, create the video post.', ['upload_method' => 'signed_url', 'storage_path' => 'videos/1/example.mp4', 'video_url' => 'https://media.example.com/videos/1/example.mp4', 'upload_url' => 'https://storage.example.com/presigned-upload', 'headers' => ['Content-Type' => 'video/mp4']], '200', SignedVideoUploadRequest::class, ['filename' => 'example.mp4', 'content_type' => 'video/mp4']);
        $chunkExample = ['upload_id' => '123e4567-e89b-42d3-a456-426614174000', 'total_chunks' => 1, 'filename' => 'example.mp4', 'content_type' => 'video/mp4', 'total_size' => 1024];
        $add('UploadController@videoChunk', 'Uploads', 'Upload a video chunk', 'Send multipart/form-data. Generate one UUID per upload. Chunk index is zero-based and less than total_chunks. Chunks are at most 2 MiB; total video size is at most 524288000 bytes. Reusing an index replaces that chunk. Keep all session metadata identical.', ['upload_method' => 'chunked', 'upload_id' => $chunkExample['upload_id'], 'chunk_index' => 0, 'total_chunks' => 1], '201', ChunkUploads::rules(true), $chunkExample + ['chunk_index' => 0, 'chunk' => '@/path/to/chunk']);
        $add('UploadController@completeVideoChunks', 'Uploads', 'Complete a chunked upload', 'Assembles the signed-in user’s upload. All chunks must exist; total_size and metadata must match exactly and assembled media must have a supported MIME type. Completion removes the session; it is not repeatable. Then submit storage_path to POST /videos.', ['upload_method' => 'chunked', 'storage_path' => 'videos/1/example.mp4', 'video_url' => 'https://media.example.com/videos/1/example.mp4'], '201', ChunkUploads::rules(), $chunkExample);
        return $result;
    }

    public static function schemas(): array
    {
        $date = '2026-09-29T10:00:00.000000Z';
        $user = ['id' => 1, 'name' => 'Alex Morgan', 'username' => 'alex', 'avatar' => null, 'bio' => null, 'followers_count' => 0, 'following_count' => 0, 'following' => false, 'cover_url' => null, 'cover_video_url' => null];
        $profile = array_diff_key($user, array_flip(['cover_url', 'cover_video_url'])) + ['email' => 'alex@example.com', 'email_verified_at' => $date, 'likes_count' => 0, 'videos_count' => 1];
        $audio = ['viewer' => ['saved' => false], 'id' => 1, 'title' => 'Original sound - Alex Morgan', 'audio_url' => 'https://media.example.com/sounds/1/example.m4a', 'duration' => 12, 'origin' => 'original', 'status' => 'ready', 'source_video_id' => 1, 'videos_count' => 1, 'creator' => $user, 'created_at' => $date];
        $video = ['id' => 1, 'audio_id' => 1, 'audio_mode' => 'replace', 'audio_settings' => null, 'audio' => $audio, 'video_url' => 'https://media.example.com/videos/1/example.mp4', 'thumbnail_url' => null, 'caption' => 'Hello #world', 'sound_name' => null, 'sound_artist' => null, 'sound_provider' => 'original', 'sound_external_id' => null, 'sound_preview_url' => null, 'music' => 'Original sound', 'tags' => ['#world'], 'duration' => 12, 'location_name' => null, 'visibility' => 'public', 'high_quality_upload' => false, 'scheduled_at' => null, 'pinned_at' => null, 'edit' => ['trim_start' => null, 'trim_end' => null, 'cut_points' => [], 'cover_time' => null, 'crop_mode' => 'fit', 'text_overlay' => null, 'original_audio_muted' => false, 'filter_settings' => null, 'effect_settings' => null], 'status' => 'published', 'user' => $user, 'stats' => ['views' => 0, 'likes' => 0, 'comments' => 0, 'saves' => 0, 'shares' => 0], 'viewer' => ['liked' => false, 'saved' => false, 'following' => false], 'created_at' => $date];
        $notification = [
            'id' => 1,
            'type' => 'video_liked',
            'data' => [
                'actor' => ['id' => 1, 'username' => 'alex', 'name' => 'Alex Morgan', 'avatar' => null],
                'message' => 'Alex Morgan liked your video.',
                'video_id' => 1,
                'comment_id' => null,
                'parent_id' => null,
                'excerpt' => null,
                'reaction_type' => null,
            ],
            'read_at' => null,
            'created_at' => $date,
        ];
        return ['Audio' => $audio, 'ProcessingAudio' => array_replace($audio, ['origin' => 'upload', 'status' => 'processing', 'audio_url' => null, 'source_video_id' => null, 'duration' => null, 'videos_count' => 0]), 'User' => $user, 'Profile' => $profile, 'MyProfile' => $profile + ['social_identities' => []], 'Video' => $video, 'ProcessingVideo' => array_replace($video, ['status' => 'processing', 'duration' => null]), 'Comment' => ['id' => 1, 'video_id' => 1, 'parent_id' => null, 'body' => 'Love this!', 'created_at' => $date, 'user' => $user, 'replies_count' => 0, 'reactions' => ['like' => 0, 'love' => 0, 'haha' => 0, 'wow' => 0, 'sad' => 0, 'angry' => 0], 'viewer_reaction' => null], 'Notification' => $notification, 'ReadNotification' => array_replace($notification, ['read_at' => $date])];
    }

    public static function expand(mixed $value, array $schemas): mixed
    {
        if (is_string($value) && str_starts_with($value, '$')) {
            return $schemas[substr($value, 1)];
        }
        return is_array($value) ? array_map(fn($item) => self::expand($item, $schemas), $value) : $value;
    }

    public static function parameters(array|string $rules): array
    {
        if (is_string($rules)) {
            // FormRequest constructors validate the current HTTP request; inspect rules only.
            $rules = (new \ReflectionClass($rules))->newInstanceWithoutConstructor()->rules();
        }
        $parameters = [];
        foreach ($rules as $name => $constraints) {
            $parts = [];
            foreach ((array) $constraints as $rule) {
                if (is_array($rule)) {
                    foreach ($rule as $key => $values) {
                        // Do not expose runtime user IDs in the profile unique rule.
                        $parts[] = $key === 'unique' ? 'unique (excluding your account)' : $key . ':' . implode(',', (array) $values);
                    }
                } else {
                    $parts[] = $rule;
                }
            }
            $type = 'string';
            foreach (['integer', 'numeric', 'boolean', 'array', 'file', 'image'] as $candidate) {
                if (in_array($candidate, $parts, true)) {
                    $type = match ($candidate) { 'numeric' => 'number', 'image' => 'file', default => $candidate};
                }
            }
            if (in_array($name, ['filter_settings', 'effect_settings'], true)) {
                $type = 'object';
            }
            $parameters[] = ['name' => $name, 'in' => 'body', 'type' => $type, 'required' => in_array('required', $parts, true), 'description' => implode(' · ', $parts)];
            if (in_array('confirmed', $parts, true)) {
                $parameters[] = ['name' => $name . '_confirmation', 'in' => 'body', 'type' => 'string', 'required' => true, 'description' => 'Must exactly match ' . $name . '.'];
            }
        }
        return $parameters;
    }
}
