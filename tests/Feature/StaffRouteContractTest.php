<?php

namespace Tests\Feature;

use Spark\Http\Routing\Router;
use Tests\TestCase;

class StaffRouteContractTest extends TestCase
{
    public function testStepTwoRetiresStaffApiAndPreservesEveryCustomerMethodPathPair(): void
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/staff/api-route-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        $expected = $fixture['customer_routes'];
        $actual = [];

        foreach (app(Router::class)->getRoutes() as $name => $route) {
            if (str_starts_with($route['path'], '/api/v1/')) {
                $actual[] = $route['method'] . ' ' . $route['path'];
            }
        }
        sort($actual);
        sort($expected);
        $this->assertSame(157, count($fixture['customer_routes']));
        $this->assertSame(15, count($fixture['step_2_migration']));
        $this->assertSame($expected, $actual);
        $this->assertFalse(is_file(dirname(__DIR__, 2) . '/routes/staff.php'));
        $this->assertSame('/', parse_url((string) route('staff.dashboard'), PHP_URL_PATH) ?: '/');
        $this->assertSame('/login', parse_url((string) route('staff.login'), PHP_URL_PATH));
    }

    public function testPanelRoutesUseOrderedGuardMfaAndPermissionMiddleware(): void
    {
        $names = [];

        foreach (app(Router::class)->getRoutes() as $name => $route) {
            if (!is_string($name) || !str_starts_with($name, 'staff.')) {
                continue;
            }
            $this->assertTrue(str_starts_with($name, 'staff.'));
            $this->assertFalse(in_array($name, $names, true));
            $names[] = $name;
            $this->assertFalse(str_contains($route['path'], 'xpn-admin'));

            if ($name === 'staff.dashboard' || $name === 'staff.attention' || str_starts_with($name, 'staff.team')) {
                $middleware = $route['middleware'];
                $this->assertSame('staff.web', $middleware[0]);
                $this->assertSame('staff.mfa', $middleware[1]);
                $this->assertTrue(str_starts_with($middleware[2], 'staff.can:'));
                $this->assertSame('staff.inertia', $middleware[3]);
            }
        }
        $this->assertSame(66, count($names));
    }
}
