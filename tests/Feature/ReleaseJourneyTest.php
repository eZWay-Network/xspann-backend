<?php

namespace Tests\Feature;

use Spark\Foundation\Application;
use Tests\Support\{ReleaseJourneys, ReleaseUpgrades};
use Tests\TestCase;

class ReleaseJourneyTest extends TestCase
{
    use ReleaseJourneys, ReleaseUpgrades;

    protected function createApplication(): Application
    {
        $app = parent::createApplication();
        $app->mergeConfig(['database' => ['connections' => ['sqlite' => ['file' => $this->storagePath . '/journey.sqlite']]]]);

        return $app;
    }
}
