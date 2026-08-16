<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Compatibility\Rules;

use Hopheartsceo\ReleaseGuard\Compatibility\Rules\UnanalyzableMigrationOperationRule;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Domain\Schema\SchemaDelta;
use Hopheartsceo\ReleaseGuard\Domain\Schema\UnanalyzableMigrationOperation;
use PHPUnit\Framework\TestCase;

final class UnanalyzableMigrationOperationRuleTest extends TestCase
{
    public function test_it_reports_unknown_warning_for_a_dynamic_table(): void
    {
        $change = new UnanalyzableMigrationOperation(
            operation: 'dropColumn',
            table: null,
            column: 'phone',
            reason: 'dynamic_table',
            file: 'database/migrations/dynamic_table.php',
            line: 10,
        );

        $findings = (new UnanalyzableMigrationOperationRule())->evaluate(
            new SchemaDelta([$change]),
            new ApplicationSnapshot(),
        );

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB006', $finding->code);
        $this->assertSame(Severity::WARNING, $finding->severity);
        $this->assertSame(Confidence::UNKNOWN, $finding->confidence);

        $this->assertNull($finding->table);
        $this->assertSame('phone', $finding->column);
        $this->assertNull($finding->usage);
        $this->assertSame($change, $finding->change);
    }

    public function test_it_reports_unknown_warning_for_a_dynamic_column(): void
    {
        $change = new UnanalyzableMigrationOperation(
            operation: 'dropColumn',
            table: 'users',
            column: null,
            reason: 'dynamic_column',
            file: 'database/migrations/dynamic_column.php',
            line: 12,
        );

        $findings = (new UnanalyzableMigrationOperationRule())->evaluate(
            new SchemaDelta([$change]),
            new ApplicationSnapshot(),
        );

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB006', $finding->code);
        $this->assertSame(Severity::WARNING, $finding->severity);
        $this->assertSame(Confidence::UNKNOWN, $finding->confidence);

        $this->assertSame('users', $finding->table);
        $this->assertNull($finding->column);
        $this->assertNull($finding->usage);
        $this->assertSame($change, $finding->change);
    }

    public function test_it_ignores_analyzable_schema_changes(): void
    {
        $findings = (new UnanalyzableMigrationOperationRule())->evaluate(
            new SchemaDelta(),
            new ApplicationSnapshot(),
        );

        $this->assertSame([], $findings);
    }
}
