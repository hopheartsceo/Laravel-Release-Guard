<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Compatibility\Rules;

use Hopheartsceo\ReleaseGuard\Compatibility\Rules\DroppedColumnStillReferencedRule;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Application\WriteUsage;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Domain\Schema\DroppedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\SchemaDelta;
use PHPUnit\Framework\TestCase;

final class DroppedColumnStillReferencedWriteUsageTest extends TestCase
{
    public function test_dropped_column_present_in_literal_insert_is_a_definite_blocker(): void
    {
        $change = new DroppedColumn(
            table: 'users',
            column: 'phone',
            file: 'database/migrations/drop_phone_from_users.php',
            line: 12,
        );

        $write = new WriteUsage(
            table: 'users',
            operation: 'insert',
            columns: ['name', 'phone'],
            reason: null,
            file: 'app/Services/UserCreator.php',
            line: 18,
        );

        $findings = (new DroppedColumnStillReferencedRule())->evaluate(
            new SchemaDelta([$change]),
            new ApplicationSnapshot([$write]),
        );

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB001', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('users', $finding->table);
        $this->assertSame('phone', $finding->column);
        $this->assertSame($write, $finding->usage);
        $this->assertSame($change, $finding->change);
    }

    public function test_dynamic_insert_payload_is_not_promoted_to_a_definite_db001_finding(): void
    {
        $change = new DroppedColumn(
            table: 'users',
            column: 'phone',
            file: 'database/migrations/drop_phone_from_users.php',
            line: 12,
        );

        $write = new WriteUsage(
            table: 'users',
            operation: 'insert',
            columns: null,
            reason: 'dynamic_payload',
            file: 'app/Services/UserCreator.php',
            line: 18,
        );

        $findings = (new DroppedColumnStillReferencedRule())->evaluate(
            new SchemaDelta([$change]),
            new ApplicationSnapshot([$write]),
        );

        $this->assertSame([], $findings);
    }

    public function test_eloquent_create_semantics_are_not_promoted_to_definite_db001(): void
    {
        $change = new DroppedColumn(
            table: 'users',
            column: 'phone',
            file: 'database/migrations/drop_phone_from_users.php',
            line: 12,
        );

        $write = new WriteUsage(
            table: 'users',
            operation: 'create',
            columns: null,
            reason: 'eloquent_model_create_semantics',
            file: 'app/Services/UserCreator.php',
            line: 18,
        );

        $findings = (new DroppedColumnStillReferencedRule())->evaluate(
            new SchemaDelta([$change]),
            new ApplicationSnapshot([$write]),
        );

        $this->assertSame([], $findings);
    }

    public function test_insert_for_another_table_does_not_trigger_db001(): void
    {
        $change = new DroppedColumn(
            table: 'users',
            column: 'phone',
            file: 'database/migrations/drop_phone_from_users.php',
            line: 12,
        );

        $write = new WriteUsage(
            table: 'profiles',
            operation: 'insert',
            columns: ['phone'],
            reason: null,
            file: 'app/Services/ProfileCreator.php',
            line: 18,
        );

        $findings = (new DroppedColumnStillReferencedRule())->evaluate(
            new SchemaDelta([$change]),
            new ApplicationSnapshot([$write]),
        );

        $this->assertSame([], $findings);
    }
}
