<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Feature;

use Hopheartsceo\ReleaseGuard\Analysis\Application\DatabaseUsageAnalyzer;
use Hopheartsceo\ReleaseGuard\Analysis\Migrations\MigrationAnalyzer;
use Hopheartsceo\ReleaseGuard\Compatibility\CompatibilityEngine;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Tests\TestCase;

final class RenamedTableStillReferencedIntegrationTest extends TestCase
{
    public function test_renamed_table_read_by_base_release_reaches_db004(): void
    {
        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->renameTableMigration(),
            file: 'database/migrations/rename_legacy_orders.php',
        );

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $this->baseOldTableGet(),
            file: 'app/Services/LegacyOrderReader.php',
        );

        $findings = $this->app
            ->make(CompatibilityEngine::class)
            ->analyze($delta, $snapshot);

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB004', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('legacy_orders', $finding->table);
        $this->assertNull($finding->column);
        $this->assertSame('get', $finding->usage?->operation);
    }

    public function test_renamed_table_written_by_base_release_reaches_db004(): void
    {
        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->renameTableMigration(),
            file: 'database/migrations/rename_legacy_orders.php',
        );

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $this->baseOldTableInsert(),
            file: 'app/Services/LegacyOrderCreator.php',
        );

        $findings = $this->app
            ->make(CompatibilityEngine::class)
            ->analyze($delta, $snapshot);

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB004', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('legacy_orders', $finding->table);
        $this->assertSame('insert', $finding->usage?->operation);
    }

    public function test_usage_of_new_table_does_not_trigger_db004(): void
    {
        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->renameTableMigration(),
            file: 'database/migrations/rename_legacy_orders.php',
        );

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $this->baseNewTableGet(),
            file: 'app/Services/OrderReader.php',
        );

        $findings = $this->app
            ->make(CompatibilityEngine::class)
            ->analyze($delta, $snapshot);

        $this->assertSame([], $findings);
    }

    public function test_db004_can_be_disabled_through_configuration(): void
    {
        config()->set('release-guard.rules.DB004', false);

        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->renameTableMigration(),
            file: 'database/migrations/rename_legacy_orders.php',
        );

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $this->baseOldTableGet(),
            file: 'app/Services/LegacyOrderReader.php',
        );

        $findings = $this->app
            ->make(CompatibilityEngine::class)
            ->analyze($delta, $snapshot);

        $this->assertSame([], $findings);
    }

    private function renameTableMigration(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('legacy_orders', 'orders');
    }
};
PHP;
    }

    private function baseOldTableGet(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('legacy_orders')->get();
PHP;
    }

    private function baseOldTableInsert(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('legacy_orders')->insert([
    'user_id' => $userId,
    'status' => 'pending',
]);
PHP;
    }

    private function baseNewTableGet(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('orders')->get();
PHP;
    }
}
