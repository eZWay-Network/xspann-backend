<?php

namespace App\Http\Middlewares;

use App\Services\Staff\{Sessions, StaffException};
use Inertia\Facades\Inertia;
use Spark\Exceptions\Http\ResponseException;
use Spark\Foundation\Application;
use Spark\Foundation\Exceptions\{InvalidCsrfTokenException, ThrottleException, ValidationException};
use Spark\Http\{Request, Response};
use function get_class;
use function in_array;

final class StaffPerimeter
{
    public function handle(Request $request, \Closure $next): mixed
    {
        // API transport and the existing public utility pages keep their own responses.
        if ($request->is('api') || $request->is('api/*') || $request->is('up') || $request->is('auth/email/verify/*')) {
            return $next($request);
        }

        Application::$app->forgetInstance('auth.staff_web');
        Application::$app->instance(\Inertia\Inertia::class, new \Inertia\Inertia($request));

        Inertia::flushShared();
        Inertia::setRootView('app');
        Inertia::withEncryptedHistory();
        Inertia::withClearedHistory((bool) session()->pull('staff_clear_history', false));
        Inertia::share([
            'auth' => Inertia::always(null),
            'navigation' => Inertia::always([]),
            'csrf_token' => Inertia::always(fn() => session('csrf_token', '')),
            'page' => Inertia::always(['title' => 'Staff access', 'breadcrumbs' => []]),
            'environment' => ['app' => config('app.env', 'production'), 'billing' => config('billing.environment', 'test')],
            'urls' => ['login' => (string) route('staff.login'), 'logout' => (string) route('staff.logout')],
        ]);

        Application::$app->prepareResponseUsing(function (Response $response): void {
            $response->withHeaders([
                'Cache-Control' => 'no-store, private, max-age=0',
                'Pragma' => 'no-cache',
                'Referrer-Policy' => 'same-origin',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });

        try {
            if (
                preg_match('~^settings/(?:team(?:/invitations)?|sessions)/([^/]+)(?:/cancel|/revoke)?$~D', trim($request->getPath(), '/'), $match)
                && !in_array($match[1], ['invitations', 'revoke-all'], true)
                && !preg_match('/^[1-9][0-9]{0,17}$/D', $match[1])
            ) {
                return $this->error(404);
            }
            if (
                preg_match('~^(?:users|artists|content/(?:videos|audios|playlists)|moderation/(?:reports|appeals)|promotions/(?:boosts|placements)|billing/(?:subscriptions|invoices|checkouts|adjustments|webhooks)|releases|exports|settings/taxonomy/genres|operations/media/(?:videos|audios)|operations/(?:privacy|actions|communications))/([^/]+)(?:/|$)~D', trim($request->getPath(), '/'), $match)
                && trim($request->getPath(), '/') !== 'promotions/placements/create'
                && !preg_match('/^[1-9][0-9]{0,14}$/D', $match[1])
            ) {
                return $this->error(404);
            }
            $response = $next($request);

            if ($response instanceof Response && $response->getStatusCode() >= 400 && !$this->isProtocolResponse($response)) {
                return $this->error($response->getStatusCode());
            }

            return $response;
        } catch (ValidationException $exception) {
            if ($request->attributes['staff_disclosure'] ?? false) {
                return json(['errors' => $exception->getErrors()], 422);
            }

            // Never put credentials, codes, invitation tokens, or old input in flash.
            return Inertia::redirect($this->returnUrl($request), 303)->withErrors($exception->getErrors());
        } catch (StaffException $exception) {
            if (in_array($exception->status, [409, 422], true) && $request->isPostBack()) {
                return Inertia::redirect($this->returnUrl($request), 303)
                    ->withErrors([$exception->field => [$exception->getMessage()]]);
            }

            return $this->error($exception->status);
        } catch (InvalidCsrfTokenException) {
            return $this->error(419);
        } catch (ThrottleException) {
            return $this->error(429);
        } catch (ResponseException $exception) {
            $status = $exception->response->getStatusCode();
            if ($request->isPostBack() && $request->header('X-Inertia') && in_array($status, [409, 422], true)) {
                return Inertia::redirect($this->returnUrl($request), 303)->withErrors([
                    'form' => [
                        $status === 409
                        ? 'This record changed or is no longer eligible. Review the refreshed record before submitting again.'
                        : 'The action could not be applied. Check the values, dates and current eligibility before trying again.'
                    ],
                ]);
            }

            return $exception->response->getStatusCode() >= 400 && !$this->isProtocolResponse($exception->response)
                ? $this->error($exception->response->getStatusCode())
                : $exception->response;
        } catch (\Throwable $exception) {
            if (Application::$app->isTesting()) {
                throw $exception;
            }
            tracer_log('Staff request failed: ' . get_class($exception));

            return $this->error(500);
        } finally {
            Inertia::flushShared();
        }
    }

    private function isProtocolResponse(Response $response): bool
    {
        return $response->hasHeader('X-Inertia') || $response->hasHeader('X-Inertia-Location') || $response->hasHeader('X-Inertia-Redirect')
            || ($response->getStatusCode() === 416 && $response->hasHeader('Content-Range'));
    }

    private function returnUrl(Request $request): string
    {
        if ($request->is('login')) {
            return (string) route('staff.login');
        }

        if ($request->is('challenge')) {
            return (string) route('staff.challenge');
        }

        if ($request->is('enroll*')) {
            return (string) route('staff.enroll');
        }
        $referer = $request->referer();

        if ($referer && str_starts_with($referer, $request->getRootUrl() . '/')) {
            return Sessions::destination(substr($referer, strlen($request->getRootUrl())));
        }

        return Sessions::destination($referer);
    }

    private function error(int $status): Response
    {
        Inertia::flushShared();
        Inertia::withClearedHistory();
        Inertia::share([
            'auth' => Inertia::always(null),
            'navigation' => Inertia::always([]),
            'csrf_token' => Inertia::always(session('csrf_token', ''))
        ]);

        return Inertia::render('error', [
            'status' => $status,
            'login_url' => (string) route('staff.login'),
            'retry_url' => (string) route('staff.dashboard'),
        ])->setStatusCode($status);
    }
}
