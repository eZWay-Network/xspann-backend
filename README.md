# Xspann API

A Spark API using native ORM models, JSON resources, query builder, validation, bearer authentication, queues, and Blade documentation. Uses TinyCore 3.3.34.

Requires PHP 8.2+. Enable PDO and your database driver, `pdo_sqlite` for the default cache/queue/locks, `fileinfo`, `mbstring`, `openssl`, and `curl`. Install FFmpeg and FFprobe for video duration and cover generation. GD is used by the avatar upload test fixture.

```sh
composer install
php -d upload_max_filesize=512M -d post_max_size=520M -S 127.0.0.1:8080 -t public
```

For a new installation, copy `.env.example` to `.env` and run `php spark key:generate`. For an existing installation, keep `APP_KEY` stable and configure the existing database. The tables are already supplied; `composer setup` is the skeleton's fresh-install command and runs migrations/seeders. Tests use disposable databases.

Open `/` for the Blade route reference and `/up` for the health response. The docs group registered endpoints and show their controllers, middleware, storage disk, and queue driver. No frontend asset build is needed for this page.

Run a queue worker under your process supervisor:

```sh
php spark queue:work --sleep=1
```

The frontend's `NEXT_PUBLIC_API_URL` must point to `APP_URL/api/v1`. Its service files handle the returned bearer token, `data`, `meta`, and validation errors. Its image configuration allows `/uploads/**` on that API origin; add the S3 storage origin to `images.remotePatterns` for signed media, or the CDN origin for public media. Registration now requires email verification before login; the frontend must show the verification message instead of treating registration as an authenticated session.

## Discover API

- `GET /api/v1/discover`: public, published videos with optional caption/hashtag/creator search (`q`) and `sort=popular|latest` (default `popular`). Popular orders by total likes, comments, saves and shares, then views; both sorts break ties by creation time and ID. This is all-time popularity, not a time-window trending or personalized ranking.
- `GET /api/v1/discover/people`: suggested accounts when `q` is blank, or partial username/display-name search when present. A leading `@` is optional. Exact username matches rank first, then follower count, creation time and ID. Searching includes matching followed accounts and self; default suggestions exclude them for signed-in viewers.
- Both support guests and optional JWT viewer state, exclude inactive accounts and blocks in both directions, and use the existing `Video`/`User` resources with `data`, `links`, and `meta`. Discover videos exclude private and followers-only posts even when the viewer owns or follows them. User results contain no email or credentials.
- Query parameters: `q` up to 100 characters, `page` from 1–100000 (default 1), and `limit` from 1–100 (default 18). Invalid values return 422 rather than being clamped. Whitespace-only queries browse normally. Search is a literal substring; `%`, `_`, and `!` are escaped, and hashtags match caption text. Case folding follows the database's `LOWER`/collation behavior; Unicode text can be searched without transliteration. Empty matches return a normal 200 response with an empty list.
- Search is applied before pagination. Clients must preserve `q`, `sort`, and `limit` when loading the next page. The ID tie-break stabilizes equal results; as with the existing page-based feeds, concurrent posts or engagement changes may shift pages, so clients should deduplicate by ID.

```sh
curl -G 'https://dash-xspann.webermelon.dev/api/v1/discover' \
  -H 'Accept: application/json' --data-urlencode 'q=#travel' \
  --data-urlencode 'sort=latest' --data-urlencode 'limit=18'
curl -G 'https://dash-xspann.webermelon.dev/api/v1/discover/people' \
  -H 'Accept: application/json' --data-urlencode 'q=alex'
```

The route-generated docs at `/` and their **Export JSON** action include both endpoints. A generated snapshot is in [`docs/api-reference.json`](docs/api-reference.json). No migration, dependency update, or email-verification change is required. Publish the controller, routes, and docs together before switching the mobile client from its current `/videos` and `/users/suggestions` implementation. The new endpoints use a 120-request throttle, matching the route's native middleware semantics.

Run `php test --filter=DiscoverApiTest` for isolated search, ranking, privacy, pagination, validation, docs-export and bounded-query checks. The pre-existing email-verification expectations are intentionally unchanged.

## Configuration

| Setting | Purpose |
| --- | --- |
| `APP_URL` | Canonical API origin, including scheme and port. Used for media URLs and verification links. |
| `APP_KEY` | Stable application signing key; changing it invalidates issued tokens and verification links. |
| `APP_DEBUG` | Keep `false` in production. |
| `APP_TIMEZONE` | Use `UTC`; response timestamps use ISO 8601 UTC. |
| `FRONTEND_URL` | Frontend origin for CORS, password reset links, and share URLs. |
| `DB_CONNECTION` | `sqlite`, `mysql`, or the driver supported by the installed Spark version. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Connection values for a server database. SQLite uses `database/sqlite.db`; its path is in `config/database.php`. |
| `GOOGLE_CLIENT_ID` | Google OAuth Web/server client ID; required token audience for web and native sign-in. No client secret is needed. |
| `GOOGLE_ALLOWED_PRESENTER_IDS` | Comma-separated trusted Android/iOS OAuth client IDs allowed as token presenters (`azp`). The Web client is always allowed. |
| `API_TOKEN_EXPIRATION` | Token lifetime in minutes; default `43200` (30 days). |
| `FILESYSTEM_DISK` | `public` for locally served media; `s3` for AWS or compatible object storage. The `local` disk is private and is not a public-media destination. |
| `VIDEO_UPLOAD_MODE` | `signed` allows direct browser uploads on S3 disks. Default `local` sends uploads through the backend to `FILESYSTEM_DISK`. |
| `FFMPEG_BINARY`, `FFPROBE_BINARY` | Executable names or absolute paths. |
| `CACHE_DRIVER`, `QUEUE_DRIVER` | `sqlite` by default; use `redis` with the `REDIS_*` settings when appropriate. |
| `MAIL_SMTP`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` | Native mailer transport. Set `MAIL_SMTP=true` for SMTP. |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Sender identity. |

CORS origins and allowed headers are in `config/cors.php`. The existing frontend uses bearer headers, not cookie authentication. Add any additional deployed frontend origins there.

`CorsControl` uses Spark's native middleware without an override. Core includes CORS headers on validation/abort responses and rejects disallowed preflights with `403`; allowed preflights return `204`.

For AWS or Spaces, use `FILESYSTEM_DISK=s3` with the `AWS_*` settings in `config/storage.php`. AWS can omit `AWS_ENDPOINT`; Spark derives the regional origin. For Spaces, set it to the regional Spaces origin. Use `AWS_URL` for the public bucket/CDN URL. Signing always uses the storage origin. Keep objects private with the default `temporary_urls=true` disk setting. The API issues native signed GET URLs, valid for `storage.media_url_ttl` seconds (3600 by default), for video, thumbnail, avatar, audio, and cover fields. Stable S3 URLs are stored in the database; expiring signatures are never persisted. This avoids requiring anonymous reads to private objects. The storage key needs read, write, and delete access to managed media. Existing public objects remain public until their permissions are changed.

Bucket CORS must allow your frontend origins, `PUT`, `GET`, and `HEAD`, including the upload response headers and media range requests. API CORS cannot configure the bucket. See the official [Spaces CORS guide](https://docs.digitalocean.com/products/spaces/how-to/configure-cors/) and [presigned URL support](https://docs.digitalocean.com/products/spaces/reference/s3-compatibility/). The app uses native Spark `temporaryUploadUrl()`, `temporaryUrl()`, and streaming `downloadFile()`. Set `temporary_urls=false` only for a disk whose public bucket/CDN URLs are accessible. Signed playback uses the origin, not the configured CDN URL.

## API conventions

Send `Accept: application/json`. JSON writes use `Content-Type: application/json`; multipart writes let the browser supply the boundary. Protected endpoints require `Authorization: Bearer <token>`.

Successful single responses contain `data`. Native resource lists contain `data`, pagination `links`, and `meta`:

```json
{
  "data": [],
  "links": { "first": "...page=1", "last": "...page=1", "prev": null, "next": null },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 10, "total": 0, "from": null, "to": null }
}
```

Lists accept `page` and `limit`. Defaults: 10 for feeds/video lists, 18 for suggestions, and 20 for comments, connections, and own posts. Limits are clamped to 1–100. A page beyond the last page returns an empty list while retaining the requested `current_page`.

Spark validation returns HTTP 422 with a message and field errors:

```json
{
  "message": "The Email field is required.",
  "errors": { "email": ["The Email field is required."] }
}
```

Missing authentication returns 401. Ownership failures return 403. Unavailable/hidden videos return 404. Spark throttling returns 429. Routes use ordinary middleware such as `throttle:5,1`: five requests per minute, per HTTP method, path, and IP. Limits are auth 5, uploads 20, upload chunks 900, comments 30, shares 60, and views 120. Error wording comes from Spark; `abort()` responses also include `code`.

### Authentication and account payloads

- Register: `username`, `email`, `password`, `password_confirmation`; optional `name`, `avatar`, `bio`. Usernames use ASCII letters, numbers, dashes, and underscores (3–30 characters); passwords require at least 8 characters. `device_name` may be sent by the existing frontend.
- Registration returns 201 with `data.user`, null `data.token`/`data.token_expires_at`, and a verification message. It queues a signed confirmation link; no session is issued.
- Login: `email`, `password`, optional `device_name` (100 characters). Login (200) and refresh (200) return `data.user`, `data.token`, and `data.token_expires_at`. Login and protected endpoints require `status=active` and a verified email; otherwise they return 403.
- Profile update: any of `username`, `name`, `avatar`, `bio`. `avatar` accepts a full media URL, `bio` is at most 120 characters. Upload an avatar first and then save its returned URL through this endpoint.
- Password change: `current_password`, `password`, `password_confirmation`. The current token stays valid; other sessions are revoked.
- Forgot password: `email`. The response deliberately does not disclose whether an account exists. Reset email requests for one account are separated by 60 seconds.
- Reset password: `email`, `token`, `password`, `password_confirmation`. Tokens expire after 60 minutes, are single-use, and revoke all sessions when used.
- Verification resend is public and accepts `email`. It returns the same message for unknown, inactive, and already verified accounts. Confirmation links expire after 60 minutes; opening a valid link verifies the address, then the user can log in. Refresh replaces the current access token. Logout revokes the current token.

Profiles include: `id`, `name`, `username`, `email`, `email_verified_at`, `avatar`, `bio`, `followers_count`, `following_count`, `likes_count`, `videos_count`, and `following`. Public profiles include email. Embedded user summaries retain cover URLs and counts.

### Social sign-in

The route is `POST /api/v1/auth/social/{provider}`. Only `google` is supported; other providers return 404. Provider services live in `app/Services/Social`, and identities are stored in `auth_identities` with unique `(provider, provider_id)` and `(user_id, provider)` pairs. Each user can link one identity per provider. The table stores only `id`, `user_id`, `provider`, `provider_id`, and `created_at`; deleting a user cascades to their identities.

Set `GOOGLE_CLIENT_ID` to the Google OAuth **Web/server** client ID, also used by the native app when requesting its ID token. For Android/iOS, add their OAuth client IDs to `GOOGLE_ALLOWED_PRESENTER_IDS` (comma-separated).

Google can issue a native token with the Web client as `aud` and the Android client as `azp`; the audience must still equal `GOOGLE_CLIENT_ID`, while a present `azp` must match the Web client or an explicitly allowed presenter. See [Google’s hybrid-app claim documentation](https://developers.google.com/identity/openid-connect/openid-connect#an-id-tokens-payload).

After changing these settings on a deployed server, run `php spark config:clear` so both environment and configuration caches are refreshed. Never log raw ID tokens or request bodies on the social sign-in endpoint.

Signature verification uses native `Spark\Utils\JWT` with RS256 explicitly allowed, plus application checks for Google claims. Deploy a TinyCore build with RSA JWT support and enable OpenSSL; no separate JWT package is required.

The users migration defines `auth_identities` and a nullable password. Existing databases must match this updated schema; `php spark migrate` does not replay an already-applied users migration. No migration reset is needed for the test suite, which creates disposable databases.

Send the `credential` from Google Identity Services as an **ID token**, not an OAuth access token:

```http
POST /api/v1/auth/social/google
Content-Type: application/json
Accept: application/json

{"token":"<Google ID token>"}
```

The endpoint returns HTTP 200 for both creation and login, using the same `data.user`, `data.token`, and `data.token_expires_at` response as password login. Use the returned app token as the Bearer token. Requests are limited to five per minute. Use HTTPS in production.

Signatures are checked against cached Google public keys, along with issuer, audience, expiry, and verified email. Accounts retain Google's stable subject ID. Existing accounts can link automatically when Google establishes ownership through Gmail or Google Workspace; other matching email addresses return 409 and require password login. This follows Google's [ID-token verification guidance](https://developers.google.com/identity/gsi/web/guides/verify-google-id-token).

New accounts are active and email-verified, with a generated username, Google name/avatar when available, and no password. Users can edit their profile and use password reset to establish a password. Existing profiles and verified-account passwords stay intact. Linking an unverified registration clears its password and revokes its old tokens/reset requests. Inactive accounts cannot sign in. Returning Google users are matched by subject ID; a changed Google email does not silently replace the app email.

Errors: 404 for unsupported providers, 422 for invalid request fields, 401 for invalid Google credentials, 403 for inactive accounts, 409 for account-link conflicts, 429 for throttling, and 503 for missing configuration or unavailable Google keys. Google sign-in requires no confirmation email. The API never stores the submitted Google token or exposes provider identities in public user resources.

### Video payloads and visibility

Create a video with the `storage_path` returned by an upload. Optional fields are:

| Fields | Accepted values |
| --- | --- |
| `thumbnail_url`, `sound_preview_url` | Public URLs, up to 2048 characters. `video_url` is an output field derived from `storage_path`. |
| `caption` | Up to 2200 characters. Hashtags are returned as `tags`. |
| `sound_name`, `sound_artist`, `sound_external_id` | Up to 120 characters each. |
| `sound_provider` | `original`, `jamendo`, or `local`. |
| `location_name` | Up to 180 characters. |
| `visibility` | `public`, `followers`, or `private`. |
| `high_quality_upload`, `original_audio_muted` | JSON booleans. |
| `scheduled_at` | Date/time string. |
| `trim_start`, `cover_time` | Nonnegative numbers. |
| `trim_end` | Number greater than `trim_start`; send the start alongside it. |
| `cut_points` | Up to 20 nonnegative numbers. Returned as a JSON list. |
| `crop_mode` | `fit` or `fill`. |
| `text_overlay` | Up to 120 characters. |
| `filter_settings` | `brightness` 0–200, `contrast`/`saturation` 0–250, `warmth` -100–100, `preset` up to 60 characters. |
| `effect_settings` | `arFace`, `background`, `sticker`, `visual`, each up to 60 characters. Create only. |

PATCH supports the editable metadata and `pinned`; it does not replace `storage_path` or `video_url`. Responses contain nested `edit`, `stats`, and `viewer` objects, author summary, sound fields, dates, and status. Creation returns 201 with `status=processing`; the worker publishes it. Deletion sets `status=deleted` immediately and queues physical media and database cleanup.

Only published, visible videos enter feeds. Owners can see their unpublished/private videos through the single-video and own-posts endpoints. Followers-only videos require following the author. Likes and saves are idempotent: their first POST returns 201 with `created=true`; repeats return 200 with `created=false`. Following returns 201 even if already followed. Self-following returns 422.

Comments accept `body` (up to 1000 characters) and optional `parent_id` belonging to the same video. Lists contain root comments. Read replies through `/comments/{comment}/replies`. Deleting a parent cascades replies/reactions and recalculates the video comment count. Shares accept `channel`: `copy_link`, `native_share`, `message`, `facebook`, `whatsapp`, `telegram`, `embed`, `x`, `linkedin`, or `pinterest`. Views are deduplicated for six hours by account or guest IP/user-agent hashes.

### Blocks, reactions, reports, and notifications

- Block/unblock a numeric user ID with POST/DELETE `/users/{user}/block`; GET `/me/blocks` lists your blocks. Blocking removes follows in both directions and hides the blocked account’s profile, videos, comments, and interactions from the other account. Guests can still see public content.
- POST `/comments/{comment}/reaction` accepts `reaction_type`: `like` (default when omitted), `love`, `haha`, `wow`, `sad`, or `angry`. Each account has one reaction per comment; another POST replaces it. DELETE removes it. Comment resources include `replies_count`, counts under `reactions`, and `viewer_reaction` (null when absent).
- POST `/reports` accepts exactly one of `video_id` or `reported_user_id`, a required `reason` (255 characters), and optional `details` (2000 characters). Reports always start with `status=open`.
- GET `/notifications` lists only your notifications. PATCH `/notifications/{notification}/read` marks one read; PATCH `/notifications/read-all` marks yours read. These endpoints read existing notification records; social actions do not currently generate in-app notifications.

### Upload flow

The disk selects the final destination; the mode selects the upload transport. `FILESYSTEM_DISK=s3` with mode `local` stores files in S3 through the backend. Mode `signed` lets the browser upload directly to S3. A `public` disk always uses backend uploads. The `/uploads/videos/local` endpoint name does not select the disk.

1. POST `filename` and `content_type` to `/uploads/videos/signed-url`.
2. For `upload_method=signed_url`, PUT the file to `upload_url` with the returned `headers`. The URL expires after 15 minutes.
3. For `upload_method=multipart`, POST a multipart `file` to the returned `upload_url`. The existing frontend sends videos larger than 1 MiB through the chunk endpoints instead.
4. POST the resulting `storage_path` and video metadata to `/videos`. Poll `/me/videos` or `/videos/{video}` for publication.

Chunk requests contain `upload_id` (UUID), zero-based `chunk_index`, `total_chunks`, `filename`, `content_type`, `total_size` in bytes, and multipart `chunk`. Completion sends the same metadata without `chunk_index` or `chunk`. Keep one upload ID and unchanged metadata throughout an upload. Completion returns `upload_method=chunked`, `storage_path`, and `video_url`.

| Upload | Limit | MIME types |
| --- | --- | --- |
| Video | 512000 KiB (500 MiB) | MP4, QuickTime, WebM |
| Chunk | 2048 KiB (2 MiB), up to 10000 chunks | Raw part of the video |
| Audio | 51200 KiB (50 MiB) | See `LocalAudioUploadRequest` for the accepted audio MIME types |
| Avatar | 5120 KiB (5 MiB) | JPEG, PNG, WebP |

Audio and avatar uploads return `audio_url` and `avatar_url` respectively. Keep PHP, reverse-proxy, and web-server body limits above the allowed upload size plus multipart overhead.
### Media storage

- The public disk uses the local filesystem. Media fields store relative paths, such as `videos/1/clip.mp4`. The private `local` disk remains separate.
- S3 media fields store full stable URLs for video, avatar, thumbnail, and sound preview. Cover URLs are derived from the latest visible video. Upload responses keep `storage_path` as the object key for frontend compatibility; model writes normalize it to the full S3 URL.
- Local response paths resolve through native `media_url()` to `APP_URL/uploads/...`. Configured private S3 media resolves through native `temporaryUrl()`; external media URLs pass through unchanged. Refresh the API response after a signed URL expires.
- `videos.storage_path` is the only stored video source: a relative path for the public disk, or a stable full URL for S3. `video_url` is a resource output, not a duplicate database column. Upload responses retain the object key in `storage_path` for frontend compatibility.
- Managed files must belong to the current account and the correct media directory. API writes cannot turn another account’s private media path into a signed URL. Keep old disk configuration available when changing upload destinations so existing S3 URLs can still be resolved and deleted.

`public/uploads` links to `storage/uploads`. Deploy that symlink (or configure the equivalent server alias). Nginx/Apache or the object store serves files and handles byte ranges; there is no PHP media controller. If your deployment omits symlinks, create it with `ln -s ../storage/uploads public/uploads`. Changing `APP_URL` automatically changes local media response URLs without rewriting stored paths. Local public files are directly accessible to anyone who knows their URL; API visibility is not filesystem access control. Signed S3 URLs are also usable by anyone holding them until expiry, including after a visibility or block change. Use private S3 storage when file access needs to expire.

## Endpoints

All paths below include the `/api/v1` prefix. Public reads, shares, and views accept an optional bearer token for viewer/visibility state. Register, login, forgot/reset, verification resend, and signed email verification are public. All other writes, `/auth/me`, `/feed/following`, `/me/*`, and uploads require authentication. `{user}` means a numeric user ID for follows and blocks; `{username}` means a username for profile routes.

| Method | Path |
| --- | --- |
| POST | `/api/v1/auth/register` |
| POST | `/api/v1/auth/login` |
| POST | `/api/v1/auth/social/{provider}` |
| POST | `/api/v1/auth/forgot-password` |
| POST | `/api/v1/auth/reset-password` |
| GET | `/auth/email/verify/{user}/{hash}` |
| GET | `/api/v1/feed` |
| GET | `/api/v1/videos` |
| GET | `/api/v1/videos/{video}` |
| GET | `/api/v1/users/suggestions` |
| GET | `/api/v1/users/{username}` |
| GET | `/api/v1/users/{username}/videos` |
| GET | `/api/v1/users/{username}/followers` |
| GET | `/api/v1/users/{username}/following` |
| GET | `/api/v1/videos/{video}/comments` |
| POST | `/api/v1/videos/{video}/share` |
| POST | `/api/v1/videos/{video}/view` |
| POST | `/api/v1/auth/logout` |
| GET | `/api/v1/auth/me` |
| PUT | `/api/v1/auth/profile` |
| PUT | `/api/v1/auth/password` |
| POST | `/api/v1/auth/email/verification-notification` |
| POST | `/api/v1/auth/token/refresh` |
| GET | `/api/v1/feed/following` |
| POST | `/api/v1/uploads/avatar` |
| POST | `/api/v1/uploads/videos/signed-url` |
| POST | `/api/v1/uploads/videos/local` |
| POST | `/api/v1/uploads/videos/chunk` |
| POST | `/api/v1/uploads/videos/complete` |
| POST | `/api/v1/uploads/sounds/local` |
| GET | `/api/v1/me/videos` |
| POST | `/api/v1/videos` |
| PATCH | `/api/v1/videos/{video}` |
| DELETE | `/api/v1/videos/{video}` |
| GET | `/api/v1/me/liked-videos` |
| POST | `/api/v1/videos/{video}/like` |
| DELETE | `/api/v1/videos/{video}/like` |
| POST | `/api/v1/videos/{video}/comments` |
| DELETE | `/api/v1/comments/{comment}` |
| GET | `/api/v1/me/saved-videos` |
| POST | `/api/v1/videos/{video}/save` |
| DELETE | `/api/v1/videos/{video}/save` |
| POST | `/api/v1/users/{user}/follow` |
| DELETE | `/api/v1/users/{user}/follow` |
| GET | `/api/v1/comments/{comment}/replies` |
| POST | `/api/v1/comments/{comment}/reaction` |
| DELETE | `/api/v1/comments/{comment}/reaction` |
| GET | `/api/v1/me/blocks` |
| POST | `/api/v1/users/{user}/block` |
| DELETE | `/api/v1/users/{user}/block` |
| POST | `/api/v1/reports` |
| GET | `/api/v1/notifications` |
| PATCH | `/api/v1/notifications/read-all` |
| PATCH | `/api/v1/notifications/{notification}/read` |


Outside the API prefix: `/uploads/*` is static media, `GET /up` checks health, and `GET /api-docs` renders the Blade reference.

## Queues and deployment

`ProcessVideo` uses Spark's durable `default` queue and native process runner. It probes duration (rounded up to seconds), extracts a 720-pixel-wide JPEG cover, preserves supplied duration/thumbnail values, and publishes the video. S3 processing works in both upload modes: it checks object size before downloading, then verifies the downloaded size and actual MIME type. Missing managed sources, invalid S3 uploads, and download failures mark the video failed. Decoder/thumbnail failures can still publish without metadata, preserving the Laravel behavior. External media URLs outside the configured disk are not fetched. The job does not transcode trims, cuts, overlays, filters, effects, replacement audio, or defer publication until `scheduled_at`; these values are stored as metadata.

Signed PUT URLs do not enforce the 500 MiB limit at the storage edge; the worker rejects oversized objects before downloading or publishing them. Configure retention for abandoned/rejected objects. A signed URL remains usable until expiry, so it is not a one-time upload token. Backend mode validates uploads before storing them in S3.

`DeleteVideo` removes owned source, thumbnail, and uploaded sound files, then deletes the video row; foreign keys cascade comments, reactions, likes, saves, shares, views, and reports. Shared files still referenced by another video and external URLs are preserved. It shares the processing lock with `ProcessVideo`. Storage failures preserve the deleted row for retry, with three attempts and 30/120-second backoff. Monitor failed jobs and retry failed deletions after fixing storage access. Files remain until the worker completes; object version history and CDN/browser caches follow the storage provider’s retention policies.

`SendAccountEmail` also uses the `default` queue and native PHPMailer integration, with three attempts and 30/120-second backoff. Run the queue worker under your process supervisor and restart it after deployment. The HTTP request should not run FFmpeg or send SMTP synchronously.

```sh
php spark queue:failed
php spark videos:retry --id=123
```

The video retry command changes a failed/processing video to `processing` and dispatches it again. Do not use a generic failed-job retry for a video still marked `failed`, since the job intentionally skips videos outside `processing`.

Deploy with `public/` as the web root. Keep `storage/`, the queue database, cache/lock directories, and the application database writable by the appropriate workers. SQLite queues/locks require a shared local filesystem; use Redis for coordination across hosts. Local uploads and chunk staging also require shared storage or consistent routing. Completed chunk sessions and processing temporary files are cleaned up; configure retention for abandoned upload sessions in `storage/temp/upload-chunks`.

Backend S3 uploads need writable PHP/staging space and HTTP timeouts long enough for the final transfer. Align PHP `upload_max_filesize`/`post_max_size` and the web server body limit with the upload endpoints you expose. Chunk completion retains its parts after a storage error so it can be retried; a lost successful response requires a fresh upload session. Keep disk configuration consistent between HTTP and queue workers and restart workers after changing it.

## Code map

| Location | Responsibility |
| --- | --- |
| `routes/api.php` | The 54 versioned API endpoints and middleware assignments. |
| `app/Http/Controllers/Api/V1` | Account, video, profile, social, comment, and upload endpoints. |
| `app/Http/Requests` | Spark FormRequest rules and video editor field validation. |
| `app/Models` | Spark ORM fields, casts, relations, visibility and viewer scopes. |
| `app/Http/Resources` | Native Spark JsonResource classes for users, profiles, videos, comments, and notifications. |
| `app/Services/VideoStorage.php` | Native storage and media URL handling. |
| `app/Services/ChunkUploads.php` | Chunk manifests, locking, assembly, and cleanup. |
| `app/Jobs` | Durable video processing, deletion, and email delivery. |
| `app/Http/Controllers/DocsController.php`, `resources/views/api-docs.blade.php` | Registered-route metadata and the Blade API reference. |
| `FRAMEWORK.md` | TinyMVC development reference; verify behavior against installed TinyCore. |

List queries use native eager loading, `withCount`, `withExists`, `withSum`, and subqueries for author covers and viewer state. Resources do not query the database. Query-count tests compare one-item and twelve-item pages across feeds, comments, replies, followers, profiles, and saved/liked/own posts. Music catalog lookup remains in the frontend, as before.

The supplied schema includes `comments_reacts`, with a unique `(user_id, comment_id)` pair and cascading foreign keys, plus the existing `blocks`, `reports`, and `notifications` tables. Databases created before the edited initial migrations need the matching schema changes applied separately; rerunning migrations does not rerun an already recorded migration. No existing application database is modified by the test suite.

## Tests

```sh
php test
php test --filter=MediaApiTest
php test --filter=UploadApiTest
composer validate --strict
```

Tests cover all 54 endpoints, authentication and token expiry, Google signature/claim verification and account linking, ownership and visibility, native throttling/CORS, pagination, editor validation, social actions, multipart and chunk uploads, local/S3 media representation, static file delivery, signed upload construction, private signed playback, deletion retries/cascades, blocks, all six reactions, verification-gated login, bounded query counts, and queued processing/email. Resource assertions check exact field sets so raw model data cannot replace the public API response.

Tests use isolated SQLite databases, queue/cache files, and media directories. One test base supplies model factories and native authentication; one HTTP helper starts and stops localhost fixtures for real multipart/chunk requests and S3 transfers. No test contacts a real bucket or sends email. Most process tests use controlled executable fixtures. The real decoder test runs automatically when FFmpeg and FFprobe are on PATH and otherwise reports a skip.

Google tests generate local RSA-signed tokens and replace only the HTTP key response; they exercise the real verifier, database, and login endpoint without contacting Google. A deployed Google OAuth client still needs a browser sign-in smoke test with its configured client ID.

The two PHP files in `tests/Fixtures` are server entry points: `http-router.php` boots the API against temporary storage, and `s3-router.php` receives local S3 requests. Both are needed by the upload tests; the endpoint contract lives directly in `ApiContractTest`.

Before deployment, verify real signed GET and PUT requests, bucket permissions/CORS, SMTP, FFmpeg/FFprobe, queue supervision, and the configured production database. The web server/CDN must serve media with byte-range support; PHP does not proxy video playback. The local S3 fixture verifies application transfers, not AWS permissions or signature acceptance. The Laravel comparison is based on its routes, controllers, resources, requests, and test cases; its dependency directory is not present here.
