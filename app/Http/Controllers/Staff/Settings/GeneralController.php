<?php

namespace App\Http\Controllers\Staff\Settings;

use App\Http\Requests\Staff\SettingRequest;
use App\Services\Staff\{Context, Settings};
use Inertia\Facades\Inertia;
use Spark\Http\{Request, Response};

final class GeneralController
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/general', [
            'page' => ['title' => 'General settings', 'breadcrumbs' => []],
            'settings' => Settings::read(),
            'save_url' => Context::from($request)->can('settings.manage') ? (string) route('staff.settings.general.update') : null,
            'environment_name' => config('app.env'),
        ]);
    }

    public function update(SettingRequest $request): Response
    {
        Settings::save(Context::from($request), $request->validated()->toArray());
        Inertia::flash('success', 'Workspace setting saved.');

        return Inertia::redirect((string) route('staff.settings.general'), 303);
    }
}
