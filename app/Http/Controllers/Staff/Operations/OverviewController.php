<?php

namespace App\Http\Controllers\Staff\Operations;

use App\Queries\Staff\OperationalHealth;
use Inertia\Facades\Inertia;
use Spark\Http\Response;

final class OverviewController
{
    public function index(): Response
    {
        return Inertia::render('operations/overview', [
            'page' => ['title' => 'Operations overview', 'breadcrumbs' => []], 'health' => OperationalHealth::read(),
        ]);
    }

    public function jobs(): Response
    {
        return Inertia::render('operations/jobs', [
            'page' => ['title' => 'Jobs & workers', 'breadcrumbs' => []], 'health' => OperationalHealth::read(),
        ]);
    }
}
