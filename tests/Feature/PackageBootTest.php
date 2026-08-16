<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Hopheartsceo\ReleaseGuard\Tests\TestCase;

final class PackageBootTest extends TestCase
{
    public function test_package_configuration_is_loaded(): void
    {
        $this->assertSame(
            ['app'],
            config('release-guard.paths.application'),
        );

        $this->assertSame(
            ['database/migrations'],
            config('release-guard.paths.migrations'),
        );
    }

    public function test_release_guard_command_is_registered(): void
    {
        $this->assertArrayHasKey(
            'release-guard:check',
            Artisan::all(),
        );
    }

    public function test_unimplemented_command_does_not_report_false_success(): void
    {
        $this->artisan('release-guard:check', [
            '--against' => 'origin/master',
        ])
            ->expectsOutputToContain('Release compatibility analysis has not been implemented yet.')
            ->assertFailed();
    }
}
