<?php

/**
 * This file contains the route definitions for the web application.
 *
 * The routes defined in this file are used to map URLs to controller
 * actions or views. The routes are defined using the "router()" function,
 * which is a facade for the Hyper\Router class.
 */

use App\Http\Controllers\{Api\V1\AuthController, DocsController};
use Spark\Facades\Route;

Route::get('/auth/email/verify/{user}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware('throttle:5')
    ->name('verification.verify');

Route::get('/up', fn() => json(['status' => 'ok']));
Route::get('/', DocsController::class);
