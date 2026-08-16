<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Compatibility\Rules;

use Hopheartsceo\ReleaseGuard\Compatibility\Rules\DroppedColumnStillReferencedRule;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Application\ColumnUsage;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Domain\Schema\DroppedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\SchemaDelta;
use PHPUnit\Framework\TestCase;

final class DroppedColumnStillReferencedRuleTest extends TestCase
{
    public function test_it_reports_a_definite_blocker_when_a_dropped_column_is_still_used_by_the_base_release(): void
    {
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

        $findings = (new DroppedColumnStillReferencedRule())->evaluate(
            $schemaDelta,
            $application,
        );

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB001', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('users', $finding->table);
        $this->assertSame('phone', $finding->column);

        $this->assertSame(
            'app/Services/UserLookup.php',
            $finding->usage->file,
        );

        $this->assertSame(18, $finding->usage->line);

        $this->assertSame(
            'database/migrations/2026_08_16_000000_drop_phone_from_users.php',
            $finding->change->file,
        );

        $this->assertSame(15, $finding->change->line);
    }

    public function test_it_does_not_report_when_the_base_release_uses_a_different_column(): void
    {
        $application = new ApplicationSnapshot([
            new ColumnUsage(
                table: 'users',
                column: 'email',
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

        $findings = (new DroppedColumnStillReferencedRule())->evaluate(
            $schemaDelta,
            $application,
        );

        $this->assertSame([], $findings);
    }

    public function test_it_does_not_report_when_the_base_release_uses_the_same_column_on_a_different_table(): void
    {
        $application = new ApplicationSnapshot([
            new ColumnUsage(
                table: 'contacts',
                column: 'phone',
                operation: 'where',
                file: 'app/Services/ContactLookup.php',
                line: 22,
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

        $findings = (new DroppedColumnStillReferencedRule())->evaluate(
            $schemaDelta,
            $application,
        );

        $this->assertSame([], $findings);
    }

    public function test_it_does_not_report_when_the_dropped_column_is_not_used_by_the_base_release(): void
    {
        $application = new ApplicationSnapshot();

        $schemaDelta = new SchemaDelta([
            new DroppedColumn(
                table: 'users',
                column: 'phone',
                file: 'database/migrations/2026_08_16_000000_drop_phone_from_users.php',
                line: 15,
            ),
        ]);

        $findings = (new DroppedColumnStillReferencedRule())->evaluate(
            $schemaDelta,
            $application,
        );

        $this->assertSame([], $findings);
    }
}
