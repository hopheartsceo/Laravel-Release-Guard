<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Compatibility\Rules;

use Hopheartsceo\ReleaseGuard\Compatibility\Rules\RenamedColumnStillReferencedRule;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Application\ColumnUsage;
use Hopheartsceo\ReleaseGuard\Domain\Application\WriteUsage;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Domain\Schema\RenamedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\SchemaDelta;
use PHPUnit\Framework\TestCase;

final class RenamedColumnStillReferencedRuleTest extends TestCase
{
    public function test_old_column_used_by_base_query_is_a_definite_blocker(): void
    {
        $change = $this->rename();

        $usage = new ColumnUsage(
            table: 'users',
            column: 'phone',
            operation: 'where',
            file: 'app/Services/UserLookup.php',
            line: 18,
        );

        $findings = (new RenamedColumnStillReferencedRule())->evaluate(
            new SchemaDelta([$change]),
            new ApplicationSnapshot([$usage]),
        );

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB002', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('users', $finding->table);
        $this->assertSame('phone', $finding->column);
        $this->assertSame($usage, $finding->usage);
        $this->assertSame($change, $finding->change);
    }

    public function test_old_column_written_by_base_insert_is_a_definite_blocker(): void
    {
        $change = $this->rename();

        $usage = new WriteUsage(
            table: 'users',
            operation: 'insert',
            columns: ['name', 'phone'],
            reason: null,
            file: 'app/Services/UserCreator.php',
            line: 22,
        );

        $findings = (new RenamedColumnStillReferencedRule())->evaluate(
            new SchemaDelta([$change]),
            new ApplicationSnapshot([$usage]),
        );

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB002', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('phone', $finding->column);
        $this->assertSame($usage, $finding->usage);
    }

    public function test_base_usage_of_new_column_does_not_trigger_db002(): void
    {
        $usage = new ColumnUsage(
            table: 'users',
            column: 'mobile',
            operation: 'where',
            file: 'app/Services/UserLookup.php',
            line: 18,
        );

        $findings = (new RenamedColumnStillReferencedRule())->evaluate(
            new SchemaDelta([$this->rename()]),
            new ApplicationSnapshot([$usage]),
        );

        $this->assertSame([], $findings);
    }

    public function test_dynamic_write_is_not_promoted_to_a_definite_blocker(): void
    {
        $usage = new WriteUsage(
            table: 'users',
            operation: 'insert',
            columns: null,
            reason: 'dynamic_payload',
            file: 'app/Services/UserCreator.php',
            line: 22,
        );

        $findings = (new RenamedColumnStillReferencedRule())->evaluate(
            new SchemaDelta([$this->rename()]),
            new ApplicationSnapshot([$usage]),
        );

        $this->assertSame([], $findings);
    }

    public function test_same_column_on_another_table_does_not_trigger_db002(): void
    {
        $usage = new ColumnUsage(
            table: 'profiles',
            column: 'phone',
            operation: 'where',
            file: 'app/Services/ProfileLookup.php',
            line: 18,
        );

        $findings = (new RenamedColumnStillReferencedRule())->evaluate(
            new SchemaDelta([$this->rename()]),
            new ApplicationSnapshot([$usage]),
        );

        $this->assertSame([], $findings);
    }

    private function rename(): RenamedColumn
    {
        return new RenamedColumn(
            table: 'users',
            from: 'phone',
            to: 'mobile',
            file: 'database/migrations/rename_phone_on_users.php',
            line: 12,
        );
    }
}
