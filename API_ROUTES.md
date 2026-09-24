# XSpann RNB Laravel API Routes

Base URL:

```text
http://127.0.0.1:8000/api/v1
```

Authentication uses Laravel Sanctum. Send `Authorization: Bearer <token>` for protected endpoints.

## Auth

```http
POST /auth/register
POST /auth/login
POST /auth/forgot-password
POST /auth/reset-password
GET  /auth/email/verify/{id}/{hash}
POST /auth/logout
GET  /auth/me
PUT  /auth/profile
PUT  /auth/password
POST /auth/email/verification-notification
POST /auth/token/refresh
```

## Feed And Videos

```http
GET    /feed
GET    /feed/following
GET    /videos
GET    /videos/{id}
POST   /videos
DELETE /videos/{id}
POST   /uploads/videos/signed-url
POST   /uploads/videos/local
POST   /uploads/videos/chunk
POST   /uploads/videos/complete
```

Local media files are served from `/media/{path}` with byte-range support so large MP4/WebM/MOV files can start playback in the feed without downloading the whole file first.

## Users

```http
GET    /users/{username}
GET    /users/{username}/videos
GET    /users/{username}/followers
GET    /users/{username}/following
POST   /users/{id}/follow
DELETE /users/{id}/follow
```

## TikTok-Style Social Actions

```http
POST   /videos/{id}/like
DELETE /videos/{id}/like
GET    /videos/{id}/comments
POST   /videos/{id}/comments
DELETE /comments/{id}
POST   /videos/{id}/save
DELETE /videos/{id}/save
GET    /me/saved-videos
POST   /videos/{id}/share
```

## Local MVP Defaults

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
QUEUE_CONNECTION=database
FILESYSTEM_DISK=public
VIDEO_STORAGE_DISK=public
```

Local and staging can use `VIDEO_STORAGE_DISK=public` to store files in `storage/app/public/videos`.
Run `php artisan storage:link` so public URLs resolve under `/storage`.

DigitalOcean Spaces uses Laravel's S3-compatible filesystem adapter through the `spaces` disk. When Spaces is ready, set `VIDEO_STORAGE_DISK=spaces`.
If `spaces` is selected but credentials are missing, the API still falls back to the local multipart upload response.
The frontend uses chunked local upload for larger videos through `/uploads/videos/chunk` and `/uploads/videos/complete`, which avoids PHP development server body-size limits.
