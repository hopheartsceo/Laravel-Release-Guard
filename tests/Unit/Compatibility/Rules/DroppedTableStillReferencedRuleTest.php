<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Compatibility\Rules;

use Hopheartsceo\ReleaseGuard\Compatibility\Rules\DroppedTableStillReferencedRule;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Application\ColumnUsage;
use Hopheartsceo\ReleaseGuard\Domain\Application\WriteUsage;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Domain\Schema\DroppedTable;
use Hopheartsceo\ReleaseGuard\Domain\Schema\SchemaDelta;
use PHPUnit\Framework\TestCase;

final class DroppedTableStillReferencedRuleTest extends TestCase
{
    public function test_dropped_table_read_by_base_release_is_a_definite_blocker(): void
    {
        $change = $this->drop();

        $usage = new ColumnUsage(
            table: 'legacy_orders',
            column: 'status',
            operation: 'where',
            file: 'app/Services/LegacyOrderLookup.php',
            line: 18,
        );

        $findings = (new DroppedTableStillReferencedRule())->evaluate(
            new SchemaDelta([$change]),
            new ApplicationSnapshot([$usage]),
        );

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB003', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('legacy_orders', $finding->table);
        $this->assertNull($finding->column);
        $this->assertSame($usage, $finding->usage);
        $this->assertSame($change, $finding->change);
    }

    public function test_dropped_table_written_by_base_release_is_a_definite_blocker(): void
    {
        $change = $this->drop();

        $usage = new WriteUsage(
            table: 'legacy_orders',
            operation: 'insert',
            columns: ['user_id', 'status'],
            reason: null,
            file: 'app/Services/LegacyOrderCreator.php',
            line: 22,
        );

        $findings = (new DroppedTableStillReferencedRule())->evaluate(
            new SchemaDelta([$change]),
            new ApplicationSnapshot([$usage]),
        );

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB003', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('legacy_orders', $finding->table);
        $this->assertNull($finding->column);
        $this->assertSame($usage, $finding->usage);
    }

    public function test_usage_of_another_table_does_not_trigger_db003(): void
    {
        $usage = new ColumnUsage(
            table: 'orders',
            column: 'status',
            operation: 'where',
            file: 'app/Services/OrderLookup.php',
            line: 18,
        );

        $findings = (new DroppedTableStillReferencedRule())->evaluate(
            new SchemaDelta([$this->drop()]),
            new ApplicationSnapshot([$usage]),
        );

        $this->assertSame([], $findings);
    }

    public function test_dynamic_table_write_is_not_promoted_to_a_definite_blocker(): void
    {
        $usage = new WriteUsage(
            table: null,
            operation: 'insert',
            columns: ['status'],
            reason: 'dynamic_table',
            file: 'app/Services/DynamicWriter.php',
            line: 22,
        );

        $findings = (new DroppedTableStillReferencedRule())->evaluate(
            new SchemaDelta([$this->drop()]),
            new ApplicationSnapshot([$usage]),
        );

        $this->assertSame([], $findings);
    }

    private function drop(): DroppedTable
    {
        return new DroppedTable(
            table: 'legacy_orders',
            ifExists: false,
            file: 'database/migrations/drop_legacy_orders.php',
            line: 12,
        );
    }
}
