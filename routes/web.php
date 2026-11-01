<?php

use App\Http\Controllers\{Api\V1\AuthController, DocsController};
use App\Http\Controllers\Staff\Auth\{ChallengeController, EnrollmentController, LoginController, RecoveryController};
use App\Http\Controllers\Staff\DashboardController;
use App\Http\Controllers\Staff\Settings\{ProfileController, SecurityController, TeamController};
use App\Http\Controllers\Staff\{UserController, ArtistController, VideoController, AudioController, PlaylistController};
use App\Http\Controllers\Staff\Moderation\{ReportController, AppealController};
use App\Http\Controllers\Staff\Promotions\{BoostController, PlacementController, SurfaceController, CreditController};
use App\Http\Controllers\Staff\Operations\{PrivacyController, ActionController};
use App\Http\Controllers\Staff\Billing\{SubscriptionController, InvoiceController, CheckoutController, AdjustmentController, WebhookController};
use App\Http\Controllers\Staff\Billing\ReportController as FinanceReportController;
use App\Http\Controllers\Staff\Operations\{OverviewController as OperationsOverviewController, MediaController as StaffMediaController, CommunicationController as StaffCommunicationController, AuditController};
use App\Http\Controllers\Staff\Settings\{GeneralController, TaxonomyController, ConfigurationController};
use App\Http\Controllers\Staff\{ReleaseController as StaffReleaseController, ExportController};
use Spark\Facades\Route;

// Public browser utilities used by the separately hosted customer frontend.
Route::get('/auth/email/verify/{user}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware('throttle:5')
    ->name('verification.verify');

Route::get('/up', fn() => json(['status' => 'ok']));

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

// Community workflows share the browser guard; actions retain their own abilities.
Route::get('/users', [UserController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:users.view', 'staff.inertia'])
    ->name('staff.users.index');

Route::get('/users/{user}', [UserController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:users.view', 'staff.inertia'])
    ->name('staff.users.show');

Route::get('/artists', [ArtistController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:artists.view', 'staff.inertia'])
    ->name('staff.artists.index');

Route::get('/artists/{artist}', [ArtistController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:artists.view', 'staff.inertia'])
    ->name('staff.artists.show');

Route::get('/content/videos', [VideoController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:content.view', 'staff.inertia'])
    ->name('staff.videos.index');

Route::get('/content/videos/{video}', [VideoController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:content.view', 'staff.inertia'])
    ->name('staff.videos.show');

Route::get('/content/audios', [AudioController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:content.view', 'staff.inertia'])
    ->name('staff.audios.index');

Route::get('/content/audios/{audio}', [AudioController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:content.view', 'staff.inertia'])
    ->name('staff.audios.show');

Route::get('/content/playlists', [PlaylistController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:content.view', 'staff.inertia'])
    ->name('staff.playlists.index');

Route::get('/content/playlists/{playlist}', [PlaylistController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:content.view', 'staff.inertia'])
    ->name('staff.playlists.show');

Route::post('/users/{user}/contact-reveal', [UserController::class, 'contact'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:users.contact.view', 'staff.disclosure', 'throttle:30,1,staff-actions'])
    ->name('staff.users.contact');

Route::post('/moderation/cases', [ReportController::class, 'create'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:content.case.create', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.cases.create');

Route::get('/moderation/rights', [ReportController::class, 'rights'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:reports.view', 'staff.inertia'])
    ->name('staff.rights');

Route::get('/moderation/reports', [ReportController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:reports.view', 'staff.inertia'])
    ->name('staff.reports.index');

Route::get('/moderation/reports/{report}', [ReportController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:reports.view', 'staff.inertia'])
    ->name('staff.reports.show');

Route::get('/moderation/reports/{report}/evidence', [ReportController::class, 'evidence'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:reports.evidence.view', 'staff.disclosure'])
    ->name('staff.reports.evidence');

Route::get('/moderation/reports/{report}/media/{asset}', [ReportController::class, 'media'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:reports.evidence.view', 'staff.disclosure'])
    ->name('staff.reports.media');

Route::patch('/moderation/reports/{report}/assignment', [ReportController::class, 'assignment'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:reports.assign', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.reports.assignment');

Route::post('/moderation/reports/{report}/notes', [ReportController::class, 'note'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:reports.view', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.reports.notes');

Route::post('/moderation/reports/{report}/decision', [ReportController::class, 'decision'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:reports.decide', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.reports.decision');

Route::get('/moderation/appeals', [AppealController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:appeals.view', 'staff.inertia'])
    ->name('staff.appeals.index');

Route::get('/moderation/appeals/{appeal}', [AppealController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:appeals.view', 'staff.inertia'])
    ->name('staff.appeals.show');

Route::post('/moderation/appeals/{appeal}/decision', [AppealController::class, 'decision'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:appeals.decide', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.appeals.decision');

Route::get('/promotions/boosts', [BoostController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:boosts.view', 'staff.inertia'])
    ->name('staff.boosts.index');

Route::get('/promotions/boosts/{boost}', [BoostController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:boosts.view', 'staff.inertia'])
    ->name('staff.boosts.show');

Route::get('/promotions/boosts/{boost}/preview', [BoostController::class, 'preview'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:boosts.view', 'staff.disclosure'])
    ->name('staff.boosts.preview');

Route::post('/promotions/boosts/{boost}/decision', [BoostController::class, 'decision'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:boosts.view', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.boosts.decision');

Route::post('/promotions/boosts/{boost}/remedy', [BoostController::class, 'remedy'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:boosts.remedy', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.boosts.remedy');

Route::get('/promotions/placements', [PlacementController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:placements.view', 'staff.inertia'])
    ->name('staff.placements.index');

Route::get('/promotions/placements/create', [PlacementController::class, 'create'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:placements.manage', 'staff.inertia'])
    ->name('staff.placements.create');

Route::post('/promotions/placements', [PlacementController::class, 'store'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:placements.manage', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.placements.store');

Route::get('/promotions/placements/{placement}', [PlacementController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:placements.view', 'staff.inertia'])
    ->name('staff.placements.show');

Route::patch('/promotions/placements/{placement}', [PlacementController::class, 'update'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:placements.manage', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.placements.update');

Route::post('/promotions/placements/{placement}/evidence', [PlacementController::class, 'evidence'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:placements.evidence', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.placements.evidence');

Route::get('/promotions/surface', [SurfaceController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:promotion.surface.view', 'staff.inertia'])
    ->name('staff.surface');

Route::post('/promotions/surface/observations', [SurfaceController::class, 'observe'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:promotion.surface.record', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.surface.observe');

Route::get('/promotions/credits', [CreditController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:credits.view', 'staff.inertia'])
    ->name('staff.credits');

Route::get('/operations/privacy', [PrivacyController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:privacy.view', 'staff.inertia'])
    ->name('staff.privacy.index');

Route::get('/operations/privacy/{privacyRequest}', [PrivacyController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:privacy.view', 'staff.inertia'])
    ->name('staff.privacy.show');

Route::post('/operations/privacy/{privacyRequest}/retry', [PrivacyController::class, 'retry'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:privacy.retry', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.privacy.retry');

Route::get('/operations/actions/{operation}', [ActionController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.inertia'])
    ->name('staff.operations.show');

// Finance, operational recovery, private report exports and controlled settings.
Route::get('/billing/reports', [FinanceReportController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:analytics.finance.view', 'staff.inertia'])
    ->name('staff.billing.reports');

Route::get('/billing/subscriptions', [SubscriptionController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:billing.view', 'staff.inertia'])
    ->name('staff.billing.subscriptions.index');

Route::get('/billing/subscriptions/{record}', [SubscriptionController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:billing.view', 'staff.inertia'])
    ->name('staff.billing.subscriptions.show');

Route::get('/billing/invoices', [InvoiceController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:billing.view', 'staff.inertia'])
    ->name('staff.billing.invoices.index');

Route::get('/billing/invoices/{record}', [InvoiceController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:billing.view', 'staff.inertia'])
    ->name('staff.billing.invoices.show');

Route::get('/billing/checkouts', [CheckoutController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:billing.view', 'staff.inertia'])
    ->name('staff.billing.checkouts.index');

Route::get('/billing/checkouts/{record}', [CheckoutController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:billing.view', 'staff.inertia'])
    ->name('staff.billing.checkouts.show');

Route::get('/billing/adjustments', [AdjustmentController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:billing.view', 'staff.inertia'])
    ->name('staff.billing.adjustments.index');

Route::get('/billing/adjustments/{record}', [AdjustmentController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:billing.view', 'staff.inertia'])
    ->name('staff.billing.adjustments.show');

Route::get('/billing/webhooks', [WebhookController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:billing.view', 'staff.inertia'])
    ->name('staff.billing.webhooks.index');

Route::get('/billing/webhooks/{record}', [WebhookController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:billing.view', 'staff.inertia'])
    ->name('staff.billing.webhooks.show');

Route::post('/billing/subscriptions/{record}/reconcile', [SubscriptionController::class, 'reconcile'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:billing.reconcile', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.billing.subscriptions.reconcile');

Route::post('/billing/checkouts/{record}/recover', [CheckoutController::class, 'recover'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:billing.reconcile', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.billing.checkouts.recover');

Route::post('/billing/webhooks/{record}/retry', [WebhookController::class, 'retry'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:billing.webhooks.retry', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.billing.webhooks.retry');

Route::post('/billing/adjustments/{record}/allocation', [AdjustmentController::class, 'allocate'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:billing.adjustments.allocate', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.billing.adjustments.allocate');

Route::get('/operations/overview', [OperationsOverviewController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:operations.view', 'staff.inertia'])
    ->name('staff.operations.overview');

Route::get('/operations/jobs', [OperationsOverviewController::class, 'jobs'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:operations.view', 'staff.inertia'])
    ->name('staff.operations.jobs');

Route::get('/operations/media', [StaffMediaController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:operations.view', 'staff.inertia'])
    ->name('staff.media');

Route::get('/operations/communications', [StaffCommunicationController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:communications.view', 'staff.inertia'])
    ->name('staff.communications');

Route::get('/operations/audit', [AuditController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:audit.view', 'staff.inertia'])
    ->name('staff.audit');

Route::get('/releases', [StaffReleaseController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:operations.view', 'staff.inertia'])
    ->name('staff.releases.index');

Route::get('/releases/{record}', [StaffReleaseController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:operations.view', 'staff.inertia'])
    ->name('staff.releases.show');

Route::get('/exports', [ExportController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:exports.create', 'staff.inertia'])
    ->name('staff.exports.index');

Route::get('/exports/{export}', [ExportController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:exports.create', 'staff.inertia'])
    ->name('staff.exports.show');

Route::post('/operations/media/videos/{record}/retry', [StaffMediaController::class, 'video'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:media.retry', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.media.video.retry');

Route::post('/operations/media/audios/{record}/retry', [StaffMediaController::class, 'audio'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:media.retry', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.media.audio.retry');

Route::post('/releases/{record}/recover', [StaffReleaseController::class, 'recover'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:releases.recover', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.releases.recover');

Route::post('/operations/communications/{record}/retry', [StaffCommunicationController::class, 'retry'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:communications.retry', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.communications.retry');

Route::post('/operations/realtime/recover', [StaffCommunicationController::class, 'realtime'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:communications.retry', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.realtime.recover');

Route::post('/exports', [ExportController::class, 'store'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:exports.create', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.exports.store');

Route::get('/exports/{export}/download', [ExportController::class, 'download'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:exports.create', 'staff.disclosure'])
    ->name('staff.exports.download');

Route::get('/settings/general', [GeneralController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:settings.view', 'staff.inertia'])
    ->name('staff.settings.general');

Route::get('/settings/catalog', [ConfigurationController::class, 'catalog'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:settings.view', 'staff.inertia'])
    ->name('staff.settings.catalog');

Route::get('/settings/policies', [ConfigurationController::class, 'policies'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:settings.view', 'staff.inertia'])
    ->name('staff.settings.policies');

Route::get('/settings/taxonomy', [TaxonomyController::class, 'index'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:settings.view', 'staff.inertia'])
    ->name('staff.settings.taxonomy');

Route::get('/settings/integrations', [ConfigurationController::class, 'integrations'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:settings.view', 'staff.inertia'])
    ->name('staff.settings.integrations');

Route::get('/settings/roles', [ConfigurationController::class, 'roles'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:staff.view', 'staff.inertia'])
    ->name('staff.settings.roles');

Route::patch('/settings/general', [GeneralController::class, 'update'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:settings.manage', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.settings.general.update');

Route::post('/settings/taxonomy/genres', [TaxonomyController::class, 'store'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:taxonomy.manage', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.settings.taxonomy.store');

Route::patch('/settings/taxonomy/genres/{genre}', [TaxonomyController::class, 'update'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:taxonomy.manage', 'staff.inertia', 'throttle:30,1,staff-actions'])
    ->name('staff.settings.taxonomy.update');

Route::get('/settings/team/{member}', [TeamController::class, 'show'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.can:staff.view', 'staff.inertia'])
    ->name('staff.team.show');

Route::get('/forgot-password', [RecoveryController::class, 'forgot'])->name('staff.password.forgot');
Route::post('/forgot-password', [RecoveryController::class, 'email'])
    ->middleware('throttle:5,10,staff-password')->name('staff.password.email');
Route::get('/reset-password', [RecoveryController::class, 'reset'])->name('staff.password.reset');
Route::post('/reset-password', [RecoveryController::class, 'store'])
    ->middleware('throttle:5,10,staff-password-reset')->name('staff.password.reset.store');

Route::get('/settings/preferences', [ProfileController::class, 'preferencesPage'])
    ->middleware(['staff.web', 'staff.mfa', 'staff.inertia'])
    ->name('staff.preferences');
