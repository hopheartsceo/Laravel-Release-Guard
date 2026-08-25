<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Feature;

use Hopheartsceo\ReleaseGuard\Analysis\Migrations\MigrationAnalyzer;
use Hopheartsceo\ReleaseGuard\Compatibility\CompatibilityEngine;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Application\ColumnUsage;
use Hopheartsceo\ReleaseGuard\Domain\Application\TableUsage;
use Hopheartsceo\ReleaseGuard\Domain\Application\WriteUsage;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Domain\Schema\AddedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\DroppedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\DroppedTable;
use Hopheartsceo\ReleaseGuard\Domain\Schema\RenamedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\RenamedTable;
use Hopheartsceo\ReleaseGuard\Domain\Schema\SchemaDelta;
use Hopheartsceo\ReleaseGuard\Domain\Schema\UnanalyzableMigrationOperation;
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

    public function test_dynamic_migration_reaches_db006_as_unknown_warning(): void
    {
        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->dynamicTableMigration(),
            file: 'database/migrations/dynamic_table.php',
        );

        $engine = $this->app->make(CompatibilityEngine::class);

        $findings = $engine->analyze(
            $delta,
            new ApplicationSnapshot(),
        );

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB006', $finding->code);
        $this->assertSame(Severity::WARNING, $finding->severity);
        $this->assertSame(Confidence::UNKNOWN, $finding->confidence);
        $this->assertNull($finding->table);
        $this->assertSame('phone', $finding->column);
    }

    public function test_db006_can_be_disabled_through_configuration(): void
    {
        config()->set('release-guard.rules.DB006', false);

        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->dynamicTableMigration(),
            file: 'database/migrations/dynamic_table.php',
        );

        $engine = $this->app->make(CompatibilityEngine::class);

        $findings = $engine->analyze(
            $delta,
            new ApplicationSnapshot(),
        );

        $this->assertSame([], $findings);
    }

    public function test_public_rule_code_vocabulary_and_config_registration_remain_stable(): void
    {
        $expectedRuleCodes = [
            'DB001',
            'DB002',
            'DB003',
            'DB004',
            'DB005',
            'DB006',
        ];

        $this->assertSame(
            array_fill_keys($expectedRuleCodes, true),
            config('release-guard.rules'),
        );
        $this->assertSame(
            ['blocker', 'warning', 'info'],
            array_map(
                fn (Severity $severity): string => $severity->value,
                Severity::cases(),
            ),
        );
        $this->assertSame(
            ['definite', 'probable', 'unknown'],
            array_map(
                fn (Confidence $confidence): string => $confidence->value,
                Confidence::cases(),
            ),
        );

        $findings = $this->engineFindingsForAllRuleCodes();

        $this->assertSame(
            $expectedRuleCodes,
            array_values(array_unique(array_map(
                fn ($finding): string => $finding->code,
                $findings,
            ))),
        );

        foreach ($expectedRuleCodes as $disabledCode) {
            config()->set(
                'release-guard.rules.'.$disabledCode,
                false,
            );
            $this->app->forgetInstance(CompatibilityEngine::class);

            $registeredCodes = array_values(array_unique(array_map(
                fn ($finding): string => $finding->code,
                $this->engineFindingsForAllRuleCodes(),
            )));

            $this->assertNotContains($disabledCode, $registeredCodes);
            $this->assertSame(
                array_values(array_diff(
                    $expectedRuleCodes,
                    [$disabledCode],
                )),
                $registeredCodes,
            );

            config()->set(
                'release-guard.rules.'.$disabledCode,
                true,
            );
            $this->app->forgetInstance(CompatibilityEngine::class);
        }
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

    private function dynamicTableMigration(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

$tableName = resolveTableName();

Schema::table($tableName, function (Blueprint $table) {
    $table->dropColumn('phone');
});
PHP;
    }

    /**
     * @return list<\Hopheartsceo\ReleaseGuard\Domain\Finding\Finding>
     */
    private function engineFindingsForAllRuleCodes(): array
    {
        return $this->app->make(CompatibilityEngine::class)->analyze(
            $this->schemaDeltaForAllRuleCodes(),
            $this->applicationSnapshotForAllRuleCodes(),
        );
    }

    private function schemaDeltaForAllRuleCodes(): SchemaDelta
    {
        return new SchemaDelta([
            new DroppedColumn(
                table: 'users',
                column: 'phone',
                file: 'database/migrations/drop_phone.php',
                line: 10,
            ),
            new RenamedColumn(
                table: 'customers',
                from: 'email',
                to: 'email_address',
                file: 'database/migrations/rename_email.php',
                line: 11,
            ),
            new DroppedTable(
                table: 'legacy_orders',
                ifExists: false,
                file: 'database/migrations/drop_legacy_orders.php',
                line: 12,
            ),
            new RenamedTable(
                from: 'profiles',
                to: 'user_profiles',
                file: 'database/migrations/rename_profiles.php',
                line: 13,
            ),
            new AddedColumn(
                table: 'accounts',
                column: 'country_code',
                type: 'string',
                nullable: false,
                hasDefault: false,
                usesCurrent: false,
                file: 'database/migrations/add_country_code.php',
                line: 14,
            ),
            new UnanalyzableMigrationOperation(
                operation: 'dropColumn',
                table: null,
                column: 'legacy_flag',
                reason: 'dynamic_table',
                file: 'database/migrations/dynamic_drop.php',
                line: 15,
            ),
        ]);
    }

    private function applicationSnapshotForAllRuleCodes(): ApplicationSnapshot
    {
        return new ApplicationSnapshot([
            new ColumnUsage(
                table: 'users',
                column: 'phone',
                operation: 'where',
                file: 'app/Services/UserLookup.php',
                line: 18,
            ),
            new ColumnUsage(
                table: 'customers',
                column: 'email',
                operation: 'where',
                file: 'app/Services/CustomerLookup.php',
                line: 19,
            ),
            new TableUsage(
                table: 'legacy_orders',
                operation: 'table',
                file: 'app/Services/OrderLookup.php',
                line: 20,
            ),
            new TableUsage(
                table: 'profiles',
                operation: 'table',
                file: 'app/Services/ProfileLookup.php',
                line: 21,
            ),
            new WriteUsage(
                table: 'accounts',
                operation: 'insert',
                columns: ['name'],
                reason: null,
                file: 'app/Services/AccountCreator.php',
                line: 22,
            ),
        ]);
    }
}
