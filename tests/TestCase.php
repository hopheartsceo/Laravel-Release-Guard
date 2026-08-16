<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests;

use Hopheartsceo\ReleaseGuard\ReleaseGuardServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ReleaseGuardServiceProvider::class,
        ];
    }
}
