<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Feature;

use Hopheartsceo\ReleaseGuard\Compatibility\CompatibilityEngine;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Application\ColumnUsage;
use Hopheartsceo\ReleaseGuard\Domain\Schema\DroppedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\SchemaDelta;
use Hopheartsceo\ReleaseGuard\Tests\TestCase;

final class CompatibilityEngineRegistrationTest extends TestCase
{
    public function test_container_resolves_the_engine_with_enabled_db001_rule(): void
    {
        $engine = $this->app->make(CompatibilityEngine::class);

        $findings = $engine->analyze(
            $this->schemaDelta(),
            $this->applicationSnapshot(),
        );

        $this->assertCount(1, $findings);
        $this->assertSame('DB001', $findings[0]->code);
    }

    public function test_db001_can_be_disabled_through_configuration(): void
    {
        config()->set('release-guard.rules.DB001', false);

        $engine = $this->app->make(CompatibilityEngine::class);

        $findings = $engine->analyze(
            $this->schemaDelta(),
            $this->applicationSnapshot(),
        );

        $this->assertSame([], $findings);
    }

    private function applicationSnapshot(): ApplicationSnapshot
    {
        return new ApplicationSnapshot([
            new ColumnUsage(
                table: 'users',
                column: 'phone',
                operation: 'where',
                file: 'app/Services/UserLookup.php',
                line: 18,
            ),
        ]);
    }

    private function schemaDelta(): SchemaDelta
    {
        return new SchemaDelta([
            new DroppedColumn(
                table: 'users',
                column: 'phone',
                file: 'database/migrations/2026_08_16_000000_drop_phone_from_users.php',
                line: 15,
            ),
        ]);
    }
}
