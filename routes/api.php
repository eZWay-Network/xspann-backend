<?php

use App\Http\Controllers\Api\V1\{
    AuthController,
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
        Route::post('/auth/logout', [AuthController::class, 'logout']);
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
