<?php

namespace App\Http\Controllers\Staff\Settings;

use App\Queries\Staff\Configuration;
use Inertia\Facades\Inertia;
use Spark\Http\Response;

final class ConfigurationController
{
    public function catalog(): Response
    {
        return $this->page('catalog', 'Catalog & capabilities', Configuration::catalog());
    }

    public function policies(): Response
    {
        return $this->page('policies', 'Policies & retention', Configuration::policies());
    }

    public function integrations(): Response
    {
        return $this->page('integrations', 'Integrations', Configuration::integrations());
    }

    public function roles(): Response
    {
        $sections = [];
        foreach (\App\Services\Staff\Permissions::roles() as $role) {
            $sections[] = ['title' => ucwords(str_replace('_', ' ', $role)), 'fields' => array_map(
                fn ($ability) => ['label' => $ability, 'value' => true], \App\Services\Staff\Permissions::forRole($role))];
        }

        return $this->page('roles', 'Role permissions', $sections);
    }

    private function page(string $name, string $title, array $sections): Response
    {
        return Inertia::render('settings/' . $name, ['page' => ['title' => $title, 'breadcrumbs' => []], 'sections' => $sections]);
    }
}
