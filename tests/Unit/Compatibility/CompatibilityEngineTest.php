<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Compatibility;

use Hopheartsceo\ReleaseGuard\Compatibility\CompatibilityEngine;
use Hopheartsceo\ReleaseGuard\Compatibility\Rules\DroppedColumnStillReferencedRule;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Application\ColumnUsage;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Domain\Schema\DroppedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\SchemaDelta;
use PHPUnit\Framework\TestCase;

final class CompatibilityEngineTest extends TestCase
{
    public function test_it_runs_registered_compatibility_rules(): void
    {
        $engine = new CompatibilityEngine([
            new DroppedColumnStillReferencedRule(),
        ]);

        $application = new ApplicationSnapshot([
            new ColumnUsage(
                table: 'users',
                column: 'phone',
                operation: 'where',
                file: 'app/Services/UserLookup.php',
                line: 18,
            ),
        ]);

        $schemaDelta = new SchemaDelta([
            new DroppedColumn(
                table: 'users',
                column: 'phone',
                file: 'database/migrations/2026_08_16_000000_drop_phone_from_users.php',
                line: 15,
            ),
        ]);

        $findings = $engine->analyze(
            $schemaDelta,
            $application,
        );

        $this->assertCount(1, $findings);
        $this->assertSame('DB001', $findings[0]->code);
        $this->assertSame(Severity::BLOCKER, $findings[0]->severity);
        $this->assertSame(Confidence::DEFINITE, $findings[0]->confidence);
    }

    public function test_it_returns_no_findings_when_no_rules_are_registered(): void
    {
        $engine = new CompatibilityEngine([]);

        $findings = $engine->analyze(
            new SchemaDelta(),
            new ApplicationSnapshot(),
        );

        $this->assertSame([], $findings);
    }
}
