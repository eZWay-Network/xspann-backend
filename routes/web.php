<?php

use App\Http\Controllers\{Api\V1\AuthController, DocsController};
use App\Http\Controllers\Staff\Auth\{ChallengeController, EnrollmentController, LoginController};
use App\Http\Controllers\Staff\DashboardController;
use App\Http\Controllers\Staff\Settings\{ProfileController, SecurityController, TeamController};
use Spark\Facades\Route;

// Public browser utilities used by the separately hosted customer frontend.
Route::get('/auth/email/verify/{user}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware('throttle:5')
    ->name('verification.verify');

Route::get('/up', fn () => json(['status' => 'ok']));

Route::get('/api/docs', DocsController::class);

// The application's browser surface is the staff workspace.
Route::get('/login', [LoginController::class, 'index'])
    ->name('staff.login');

Route::post('/login', [LoginController::class, 'store'])
    ->middleware('throttle:20,5,staff-login')
    ->name('staff.login.store');

Route::get('/challenge', [ChallengeController::class, 'index'])
    ->name('staff.challenge');

Route::post('/challenge', [ChallengeController::class, 'store'])
    ->middleware('throttle:20,5,staff-challenge')
    ->name('staff.challenge.store');

Route::post('/logout', [LoginController::class, 'logout'])
    ->name('staff.logout');

Route::get('/enroll', [EnrollmentController::class, 'index'])
    ->name('staff.enroll');

Route::get('/enroll/{token}', [EnrollmentController::class, 'link'])
    ->middleware('throttle:20,5,staff-invitation')
    ->name('staff.enroll.link');

Route::post('/enroll/credential', [EnrollmentController::class, 'credential'])
    ->middleware('throttle:20,5,staff-enrollment')
    ->name('staff.enroll.credential');

Route::post('/enroll/complete', [EnrollmentController::class, 'complete'])
    ->middleware('throttle:20,5,staff-enrollment')
    ->name('staff.enroll.complete');

Route::post('/enroll/restart', [EnrollmentController::class, 'restart'])
    ->name('staff.enroll.restart');

Route::get('/', [DashboardController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:dashboard.view', 'staff.inertia'])
    ->name('staff.dashboard');

Route::get('/attention', [DashboardController::class, 'attention'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:dashboard.view', 'staff.inertia'])
    ->name('staff.attention');

Route::get('/settings/profile', [ProfileController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.inertia'])
    ->name('staff.profile');

Route::patch('/settings/profile', [ProfileController::class, 'update'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.inertia'])
    ->name('staff.profile.update');

Route::patch('/settings/preferences', [ProfileController::class, 'preferences'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.inertia'])
    ->name('staff.preferences.update');

Route::get('/settings/security', [SecurityController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.inertia'])
    ->name('staff.security');

Route::post('/settings/security/password', [SecurityController::class, 'password'])
    ->middleware(['staff.web', 'staff.mfa', 'throttle:10,5,staff-security', 'staff.inertia'])
    ->name('staff.security.password');

Route::post('/settings/security/factor', [SecurityController::class, 'factor'])
    ->middleware(['staff.web', 'staff.mfa', 'throttle:10,5,staff-security', 'staff.inertia'])
    ->name('staff.security.factor');

Route::post('/settings/security/factor/complete', [SecurityController::class, 'completeFactor'])
    ->middleware(['staff.web', 'staff.mfa', 'throttle:10,5,staff-security', 'staff.inertia'])
    ->name('staff.security.factor.complete');

Route::get('/settings/team', [TeamController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:staff.view', 'staff.inertia'])
    ->name('staff.team');

Route::post('/settings/team/invitations', [TeamController::class, 'invite'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:staff.manage', 'staff.inertia'])
    ->name('staff.team.invite');

Route::post('/settings/team/invitations/{invitation}/cancel', [TeamController::class, 'cancel'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:staff.manage', 'staff.inertia'])
    ->name('staff.team.cancel');

Route::patch('/settings/team/{member}', [TeamController::class, 'update'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:staff.manage', 'staff.inertia'])
    ->name('staff.team.update');

// Own-session revocation remains reachable when the recent-MFA window expires.
Route::post('/settings/sessions/revoke-all', [SecurityController::class, 'revokeAll'])
    ->middleware('staff.web')
    ->name('staff.sessions.revoke_all');

Route::post('/settings/sessions/{session}/revoke', [SecurityController::class, 'revoke'])
    ->middleware('staff.web')
    ->name('staff.sessions.revoke');
