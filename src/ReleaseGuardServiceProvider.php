<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard;

use Hopheartsceo\ReleaseGuard\Compatibility\CompatibilityEngine;
use Hopheartsceo\ReleaseGuard\Compatibility\Rules\DroppedColumnStillReferencedRule;
use Hopheartsceo\ReleaseGuard\Compatibility\Rules\RenamedColumnStillReferencedRule;
use Hopheartsceo\ReleaseGuard\Compatibility\Rules\RequiredColumnBreaksBaseWritesRule;
use Hopheartsceo\ReleaseGuard\Compatibility\Rules\UnanalyzableMigrationOperationRule;
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

        $this->app->singleton(
            CompatibilityEngine::class,
            function ($app): CompatibilityEngine {
                $rules = [];

                if ((bool) $app['config']->get('release-guard.rules.DB001', true)) {
                    $rules[] = $app->make(DroppedColumnStillReferencedRule::class);
                }

                if ((bool) $app['config']->get('release-guard.rules.DB002', true)) {
                    $rules[] = $app->make(RenamedColumnStillReferencedRule::class);
                }

                if ((bool) $app['config']->get('release-guard.rules.DB005', true)) {
                    $rules[] = $app->make(RequiredColumnBreaksBaseWritesRule::class);
                }

                if ((bool) $app['config']->get('release-guard.rules.DB006', true)) {
                    $rules[] = $app->make(UnanalyzableMigrationOperationRule::class);
                }

                return new CompatibilityEngine($rules);
            },
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
