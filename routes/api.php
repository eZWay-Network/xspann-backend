<?php

use App\Http\Controllers\Api\V1\{
    BillingController,
    ReleaseController,
    MediaController,
    AccountController,
    PrivacyController,
    ModerationController,
    AuthController,
    AudioController,
    AudioSaveController,
    ArtistController,
    ArtistPlaylistController,
    CommentController,
    DiscoverController,
    FeedController,
    FollowController,
    LikeController,
    SaveController,
    ShareController,
    ViewController,
    UploadController,
    UserController,
    VideoController,
    BlockController,
    CommentReactionController,
    NotificationController,
    ReportController
};
use Spark\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/plans', [BillingController::class, 'plans']);
    Route::post('/webhooks/stripe', [BillingController::class, 'webhook']);
    Route::get('/media/{token}', [MediaController::class, 'show']);
    Route::get('/artist-taxonomy', [ArtistController::class, 'taxonomy']);
    Route::get('/artists', [ArtistController::class, 'index']);
    Route::get('/artists/by-slug/{slug}', [ArtistController::class, 'bySlug']);
    Route::get('/artists/{artist}', [ArtistController::class, 'show']);
    Route::get('/artists/{artist}/videos', [ArtistController::class, 'videos']);
    Route::get('/artists/{artist}/playlists', [ArtistPlaylistController::class, 'index']);
    Route::get('/artists/{artist}/playlists/{playlist}', [ArtistPlaylistController::class, 'show']);
    Route::get('/artists/{artist}/followers', [ArtistController::class, 'followers']);

    Route::get('/policies', [AccountController::class, 'policies']);
    Route::post('/auth/onboarding', [AccountController::class, 'onboarding'])->middleware('throttle:5');
    Route::post('/account/deletion-requests', [PrivacyController::class, 'requestDeletionEmail'])->middleware('throttle:5');
    Route::post('/account/deletion-confirmations', [PrivacyController::class, 'confirmDeletion'])->middleware('throttle:5');

    Route::middleware('restricted')->group(function (): void {
        Route::get('/me/subscription', [BillingController::class, 'subscription']);
        Route::get('/me/billing/invoices', [BillingController::class, 'invoices']);
        Route::get('/billing/checkouts/{reference}', [BillingController::class, 'checkoutStatus'])->middleware('throttle:60');
        Route::post('/billing/subscription/cancel', [BillingController::class, 'cancel'])->middleware('throttle:10');
        Route::post('/auth/reauthenticate', [AccountController::class, 'reauthenticate'])->middleware('throttle:5');
        Route::post('/me/policy-acceptances', [AccountController::class, 'acceptPolicies'])->middleware('throttle:10');
        Route::post('/me/deletion-requests', [PrivacyController::class, 'deleteAccount'])->middleware('throttle:5');
        Route::post('/me/export-requests', [PrivacyController::class, 'export'])->middleware('throttle:5');
        Route::get('/me/export-requests/{export}/download', [PrivacyController::class, 'download'])->middleware('throttle:10');
        Route::get('/me/export-requests/{export}', [PrivacyController::class, 'status']);
        Route::get('/me/reports/{report}', [ModerationController::class, 'ownReport']);
        Route::get('/me/moderation-decisions', [ModerationController::class, 'ownDecisions']);
        Route::get('/me/moderation-appeals', [ModerationController::class, 'ownAppeals']);
        Route::post('/me/moderation-appeals', [ModerationController::class, 'appeal'])->middleware('throttle:10');
        Route::post('/auth/logout', [AuthController::class, 'logout']);
    });

    Route::middleware('staff')->group(function (): void {
        Route::get('/admin/reports', [ModerationController::class, 'reports']);
        Route::get('/admin/reports/{report}', [ModerationController::class, 'report']);
        Route::post('/admin/reports/{report}/decision', [ModerationController::class, 'decide'])->middleware('throttle:30');
        Route::get('/admin/moderation-appeals', [ModerationController::class, 'appeals']);
        Route::post('/admin/moderation-appeals/{appeal}/decision', [ModerationController::class, 'decideAppeal'])->middleware('throttle:30');
    });

    Route::get('/audios', [AudioController::class, 'index']);
    Route::get('/audios/{audio}', [AudioController::class, 'show']);
    Route::get('/audios/{audio}/videos', [AudioController::class, 'videos']);
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5');
    Route::post('/auth/social/{provider}', [AuthController::class, 'social'])->middleware('throttle:5');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5');

    Route::post('/auth/email/verification-notification', [AuthController::class, 'resendVerification'])->middleware('throttle:5');

    Route::get('/feed', [FeedController::class, 'index']);
    Route::get('/discover', [DiscoverController::class, 'index'])->middleware('throttle:120');
    Route::get('/discover/people', [DiscoverController::class, 'people'])->middleware('throttle:120');
    Route::get('/videos', [VideoController::class, 'index']);
    Route::get('/videos/{video}', [VideoController::class, 'show']);
    Route::get('/users/suggestions', [UserController::class, 'suggestions']);
    Route::get('/users/{user:username}', [UserController::class, 'show']);
    Route::get('/users/{user:username}/videos', [UserController::class, 'videos']);
    Route::get('/users/{user:username}/followers', [UserController::class, 'followers']);
    Route::get('/users/{user:username}/following', [UserController::class, 'following']);
    Route::get('/videos/{video}/comments', [CommentController::class, 'index']);
    Route::get('/comments/{comment}/replies', [CommentController::class, 'replies']);
    Route::post('/videos/{video}/share', [ShareController::class, 'store'])->middleware('throttle:60');
    Route::post('/videos/{video}/view', [ViewController::class, 'store'])->middleware('throttle:120');

    Route::middleware('auth')->group(function (): void {
        Route::post('/billing/portal', [BillingController::class, 'portal'])->middleware('throttle:10');
        Route::get('/me/entitlements', [BillingController::class, 'entitlements']);
        Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:10');
        Route::post('/billing/subscription/resume', [BillingController::class, 'resume'])->middleware('throttle:10');
        Route::get('/me/artist/releases', [ReleaseController::class, 'index']);
        Route::get('/me/artist/releases/{video}', [ReleaseController::class, 'show']);
        Route::patch('/me/artist/releases/{video}', [ReleaseController::class, 'update'])->middleware('throttle:30');
        Route::post('/me/artist/releases/{video}/schedule', [ReleaseController::class, 'schedule'])->middleware('throttle:30');
        Route::post('/me/artist/releases/{video}/cancel-schedule', [ReleaseController::class, 'cancel'])->middleware('throttle:30');
        Route::post('/me/artist/releases/{video}/publish', [ReleaseController::class, 'publish'])->middleware('throttle:30');
        Route::post('/me/videos/{video}/retry', [ReleaseController::class, 'retry'])->middleware('throttle:5');
        Route::get('/me/videos/{video}/preview/{asset}', [ReleaseController::class, 'preview']);

        Route::get('/me/artist', [ArtistController::class, 'mine']);
        Route::put('/me/artist', [ArtistController::class, 'save'])->middleware('throttle:30');
        Route::put('/me/artist/links', [ArtistController::class, 'links'])->middleware('throttle:30');
        Route::get('/me/artist/analytics', [ArtistController::class, 'analytics']);
        Route::get('/me/artist/fans/summary', [ArtistController::class, 'fans']);
        Route::post('/artists/{artist}/follow', [ArtistController::class, 'follow'])->middleware('throttle:60');
        Route::delete('/artists/{artist}/follow', [ArtistController::class, 'unfollow']);
        Route::post('/uploads/artist-image', [UploadController::class, 'artistImage'])->middleware('throttle:20');
        Route::get('/me/artist/playlists', [ArtistPlaylistController::class, 'mine']);
        Route::post('/me/artist/playlists', [ArtistPlaylistController::class, 'store'])->middleware('throttle:30');
        Route::get('/me/artist/playlists/{playlist}', [ArtistPlaylistController::class, 'ownShow']);
        Route::patch('/me/artist/playlists/{playlist}', [ArtistPlaylistController::class, 'update'])->middleware('throttle:30');
        Route::delete('/me/artist/playlists/{playlist}', [ArtistPlaylistController::class, 'destroy']);
        Route::put('/me/artist/playlists/{playlist}/items', [ArtistPlaylistController::class, 'items'])->middleware('throttle:30');

        Route::get('/me/blocks', [BlockController::class, 'index']);
        Route::post('/users/{user}/block', [BlockController::class, 'store'])->middleware('throttle:30');
        Route::delete('/users/{user}/block', [BlockController::class, 'destroy']);
        Route::post('/comments/{comment}/reaction', [CommentReactionController::class, 'store'])->middleware('throttle:60');
        Route::delete('/comments/{comment}/reaction', [CommentReactionController::class, 'destroy']);
        Route::post('/reports', [ReportController::class, 'store'])->middleware('throttle:10');
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::patch('/notifications/read-all', [NotificationController::class, 'readAll']);
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'read']);
        Route::delete('/notifications/clear-all', [NotificationController::class, 'clearAll']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::put('/auth/profile', [AuthController::class, 'update']);
        Route::put('/auth/password', [AuthController::class, 'changePassword']);
        Route::post('/auth/token/refresh', [AuthController::class, 'refreshToken']);

        Route::get('/feed/following', [FeedController::class, 'following']);
        Route::post('/uploads/avatar', [UploadController::class, 'avatar'])->middleware('throttle:20');
        Route::post('/uploads/videos/signed-url', [UploadController::class, 'video'])->middleware('throttle:20');
        Route::post('/uploads/videos/local', [UploadController::class, 'local'])->middleware('throttle:20');
        Route::post('/uploads/videos/chunk', [UploadController::class, 'videoChunk'])->middleware('throttle:900');
        Route::post('/uploads/videos/complete', [UploadController::class, 'completeVideoChunks'])->middleware('throttle:20');
        Route::post('/uploads/sounds/local', [UploadController::class, 'audio'])->middleware('throttle:20');

        Route::get('/me/saved-audios', [AudioSaveController::class, 'index']);
        Route::post('/audios/{audio}/save', [AudioSaveController::class, 'store']);
        Route::delete('/audios/{audio}/save', [AudioSaveController::class, 'destroy']);

        Route::post('/audios', [AudioController::class, 'store']);
        Route::patch('/audios/{audio}', [AudioController::class, 'update']);
        Route::delete('/audios/{audio}', [AudioController::class, 'destroy']);

        Route::get('/me/videos', [VideoController::class, 'mine']);
        Route::post('/videos', [VideoController::class, 'store']);
        Route::patch('/videos/{video}', [VideoController::class, 'update']);
        Route::delete('/videos/{video}', [VideoController::class, 'destroy']);

        Route::get('/me/liked-videos', [LikeController::class, 'index']);
        Route::post('/videos/{video}/like', [LikeController::class, 'store']);
        Route::delete('/videos/{video}/like', [LikeController::class, 'destroy']);

        Route::post('/videos/{video}/comments', [CommentController::class, 'store'])->middleware('throttle:30');
        Route::delete('/comments/{comment}', [CommentController::class, 'destroy']);

        Route::get('/me/saved-videos', [SaveController::class, 'index']);
        Route::post('/videos/{video}/save', [SaveController::class, 'store']);
        Route::delete('/videos/{video}/save', [SaveController::class, 'destroy']);

        Route::post('/users/{user}/follow', [FollowController::class, 'store']);
        Route::delete('/users/{user}/follow', [FollowController::class, 'destroy']);
    });
});
