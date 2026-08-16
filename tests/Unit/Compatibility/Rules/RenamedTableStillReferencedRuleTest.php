<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Compatibility\Rules;

use Hopheartsceo\ReleaseGuard\Compatibility\Rules\RenamedTableStillReferencedRule;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Application\ColumnUsage;
use Hopheartsceo\ReleaseGuard\Domain\Application\TableUsage;
use Hopheartsceo\ReleaseGuard\Domain\Application\WriteUsage;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Domain\Schema\RenamedTable;
use Hopheartsceo\ReleaseGuard\Domain\Schema\SchemaDelta;
use PHPUnit\Framework\TestCase;

final class RenamedTableStillReferencedRuleTest extends TestCase
{
    public function test_old_table_read_by_base_release_is_a_definite_blocker(): void
    {
        $change = $this->rename();

        $usage = new TableUsage(
            table: 'legacy_orders',
            operation: 'get',
            file: 'app/Services/LegacyOrderReader.php',
            line: 18,
        );

        $findings = (new RenamedTableStillReferencedRule())->evaluate(
            new SchemaDelta([$change]),
            new ApplicationSnapshot([$usage]),
        );

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB004', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('legacy_orders', $finding->table);
        $this->assertNull($finding->column);
        $this->assertSame($usage, $finding->usage);
        $this->assertSame($change, $finding->change);
    }

    public function test_old_table_column_usage_is_a_definite_blocker(): void
    {
        $usage = new ColumnUsage(
            table: 'legacy_orders',
            column: 'status',
            operation: 'where',
            file: 'app/Services/LegacyOrderLookup.php',
            line: 18,
        );

        $findings = (new RenamedTableStillReferencedRule())->evaluate(
            new SchemaDelta([$this->rename()]),
            new ApplicationSnapshot([$usage]),
        );

        $this->assertCount(1, $findings);
        $this->assertSame('DB004', $findings[0]->code);
        $this->assertSame($usage, $findings[0]->usage);
    }

    public function test_old_table_write_is_a_definite_blocker(): void
    {
        $usage = new WriteUsage(
            table: 'legacy_orders',
            operation: 'insert',
            columns: ['user_id', 'status'],
            reason: null,
            file: 'app/Services/LegacyOrderCreator.php',
            line: 22,
        );

        $findings = (new RenamedTableStillReferencedRule())->evaluate(
            new SchemaDelta([$this->rename()]),
            new ApplicationSnapshot([$usage]),
        );

        $this->assertCount(1, $findings);
        $this->assertSame('DB004', $findings[0]->code);
        $this->assertSame($usage, $findings[0]->usage);
    }

    public function test_usage_of_new_table_does_not_trigger_db004(): void
    {
        $usage = new TableUsage(
            table: 'orders',
            operation: 'get',
            file: 'app/Services/OrderReader.php',
            line: 18,
        );

        $findings = (new RenamedTableStillReferencedRule())->evaluate(
            new SchemaDelta([$this->rename()]),
            new ApplicationSnapshot([$usage]),
        );

        $this->assertSame([], $findings);
    }

    public function test_dynamic_table_usage_is_not_promoted_to_a_definite_blocker(): void
    {
        $usage = new WriteUsage(
            table: null,
            operation: 'insert',
            columns: ['status'],
            reason: 'dynamic_table',
            file: 'app/Services/DynamicWriter.php',
            line: 22,
        );

        $findings = (new RenamedTableStillReferencedRule())->evaluate(
            new SchemaDelta([$this->rename()]),
            new ApplicationSnapshot([$usage]),
        );

        $this->assertSame([], $findings);
    }

    private function rename(): RenamedTable
    {
        return new RenamedTable(
            from: 'legacy_orders',
            to: 'orders',
            file: 'database/migrations/rename_legacy_orders.php',
            line: 12,
        );
    }
}
