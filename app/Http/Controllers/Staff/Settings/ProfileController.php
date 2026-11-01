<?php

namespace App\Http\Controllers\Staff\Settings;

use App\Http\Requests\Staff\{PreferencesRequest, ProfileRequest};
use App\Services\Staff\{Context, Sessions, StaffException};
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class ProfileController
{
    public function index(Request $request): Response
    {
        return $this->page($request, 'settings/profile');
    }

    public function preferencesPage(Request $request): Response
    {
        return $this->page($request, 'settings/preferences');
    }

    private function page(Request $request, string $component): Response
    {
        $context = Context::from($request);
        $preferences = DB::table('staff_preferences')->where('user_id', $context->user->id)->first();

        return Inertia::render($component, [
            'profile' => ['name' => $context->user->name, 'username' => $context->user->username],
            'preferences' => [
                'timezone' => $preferences->timezone ?? 'UTC', 'locale' => $preferences->locale ?? 'en',
                'page_size' => (int) ($preferences->page_size ?? 20), 'attention_enabled' => (bool) ($preferences->attention_enabled ?? true),
                'version' => (int) ($preferences->version ?? 0),
            ],
            'timezones' => \DateTimeZone::listIdentifiers(),
            'profile_url' => (string) route('staff.profile.update'),
            'preferences_url' => (string) route('staff.preferences.update'),
            'page' => ['title' => $component === 'settings/preferences' ? 'Preferences' : 'My profile', 'breadcrumbs' => []],
        ]);
    }

    public function update(ProfileRequest $request): Response
    {
        $name = trim($request->validated('name'));

        if ($name === '') {
            throw new StaffException('Enter your display name.', 422, 'name');
        }

        if (!Context::from($request)->user->update(['name' => $name])) {
            throw new StaffException('Your display name could not be saved. Please try again.', 503);
        }
        Inertia::flash('success', 'Your display name has been saved.');

        return Inertia::redirect((string) route('staff.profile'), 303);
    }

    public function preferences(PreferencesRequest $request): Response
    {
        $context = Context::from($request);
        $data = $request->validated()->toArray();
        \App\Services\Staff\Team::locked(function () use ($context, $data): void {
            Sessions::requireCurrent($context);
            $current = DB::table('staff_preferences')->where('user_id', $context->user->id)->first();

            if ((int) ($current->version ?? 0) !== (int) $data['version']) {
                throw new StaffException('Preferences changed in another tab. Reload before saving.', 409);
            }
            DB::table('staff_preferences')->upsert([
                ...$data, 'version' => (int) $data['version'] + 1, 'user_id' => $context->user->id,
                'created_at' => Sessions::timestamp(), 'updated_at' => Sessions::timestamp(),
            ], ['user_id'], ['timezone', 'locale', 'page_size', 'attention_enabled', 'version', 'updated_at']);
        });
        Inertia::flash('success', 'Your workspace preferences have been saved.');

        return Inertia::redirect((string) route(parse_url((string) $request->referer(), PHP_URL_PATH) === '/settings/preferences' ? 'staff.preferences' : 'staff.profile'), 303);
    }
}
