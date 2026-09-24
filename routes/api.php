<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\FeedController;
use App\Http\Controllers\Api\V1\FollowController;
use App\Http\Controllers\Api\V1\LikeController;
use App\Http\Controllers\Api\V1\SaveController;
use App\Http\Controllers\Api\V1\ShareController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VideoController;
use App\Http\Controllers\Api\V1\ViewController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth');
    Route::get('/auth/email/verify/{user}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware(['signed', 'throttle:auth'])
        ->name('verification.verify');

    Route::middleware('auth.optional')->group(function (): void {
        Route::get('/feed', [FeedController::class, 'index']);
        Route::get('/videos', [VideoController::class, 'index']);
        Route::get('/videos/{video}', [VideoController::class, 'show']);
        Route::get('/users/suggestions', [UserController::class, 'suggestions']);
        Route::get('/users/{user:username}', [UserController::class, 'show']);
        Route::get('/users/{user:username}/videos', [UserController::class, 'videos']);
        Route::get('/users/{user:username}/followers', [UserController::class, 'followers']);
        Route::get('/users/{user:username}/following', [UserController::class, 'following']);
        Route::get('/videos/{video}/comments', [CommentController::class, 'index']);
        Route::post('/videos/{video}/share', [ShareController::class, 'store'])->middleware('throttle:shares');
        Route::post('/videos/{video}/view', [ViewController::class, 'store'])->middleware('throttle:120,1');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::put('/auth/profile', [AuthController::class, 'update']);
        Route::put('/auth/password', [AuthController::class, 'changePassword']);
        Route::post('/auth/email/verification-notification', [AuthController::class, 'resendVerification'])->middleware('throttle:auth');
        Route::post('/auth/token/refresh', [AuthController::class, 'refreshToken']);

        Route::get('/feed/following', [FeedController::class, 'following']);
        Route::post('/uploads/avatar', [UploadController::class, 'avatar'])->middleware('throttle:uploads');
        Route::post('/uploads/videos/signed-url', [UploadController::class, 'video'])->middleware('throttle:uploads');
        Route::post('/uploads/videos/local', [UploadController::class, 'local'])->middleware('throttle:uploads');
        Route::post('/uploads/videos/chunk', [UploadController::class, 'videoChunk'])->middleware('throttle:upload-chunks');
        Route::post('/uploads/videos/complete', [UploadController::class, 'completeVideoChunks'])->middleware('throttle:uploads');
        Route::post('/uploads/sounds/local', [UploadController::class, 'audio'])->middleware('throttle:uploads');

        Route::get('/me/videos', [VideoController::class, 'mine']);
        Route::post('/videos', [VideoController::class, 'store']);
        Route::patch('/videos/{video}', [VideoController::class, 'update']);
        Route::delete('/videos/{video}', [VideoController::class, 'destroy']);

        Route::get('/me/liked-videos', [LikeController::class, 'index']);
        Route::post('/videos/{video}/like', [LikeController::class, 'store']);
        Route::delete('/videos/{video}/like', [LikeController::class, 'destroy']);

        Route::post('/videos/{video}/comments', [CommentController::class, 'store'])->middleware('throttle:comments');
        Route::delete('/comments/{comment}', [CommentController::class, 'destroy']);

        Route::get('/me/saved-videos', [SaveController::class, 'index']);
        Route::post('/videos/{video}/save', [SaveController::class, 'store']);
        Route::delete('/videos/{video}/save', [SaveController::class, 'destroy']);

        Route::post('/users/{user}/follow', [FollowController::class, 'store']);
        Route::delete('/users/{user}/follow', [FollowController::class, 'destroy']);
    });
});
