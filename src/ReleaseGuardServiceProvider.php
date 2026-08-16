<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard;

use Hopheartsceo\ReleaseGuard\Console\CheckReleaseCompatibilityCommand;
use Illuminate\Support\ServiceProvider;

final class ReleaseGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/release-guard.php',
            'release-guard',
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckReleaseCompatibilityCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/release-guard.php' => config_path('release-guard.php'),
            ], 'release-guard-config');
        }
    }
}
