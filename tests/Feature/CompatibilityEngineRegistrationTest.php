<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Feature;

use Hopheartsceo\ReleaseGuard\Analysis\Migrations\MigrationAnalyzer;
use Hopheartsceo\ReleaseGuard\Compatibility\CompatibilityEngine;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Application\ColumnUsage;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
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
}
