<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Feature;

use Hopheartsceo\ReleaseGuard\Analysis\Application\DatabaseUsageAnalyzer;
use Hopheartsceo\ReleaseGuard\Analysis\Migrations\MigrationAnalyzer;
use Hopheartsceo\ReleaseGuard\Compatibility\CompatibilityEngine;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Tests\TestCase;

final class DroppedTableStillReferencedIntegrationTest extends TestCase
{
    public function test_dropped_table_read_by_base_release_reaches_db003(): void
    {
        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->dropLegacyOrdersMigration(),
            file: 'database/migrations/drop_legacy_orders.php',
        );

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $this->baseGet(),
            file: 'app/Services/LegacyOrderReader.php',
        );

        $findings = $this->app
            ->make(CompatibilityEngine::class)
            ->analyze($delta, $snapshot);

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB003', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('legacy_orders', $finding->table);
        $this->assertNull($finding->column);
        $this->assertSame('get', $finding->usage?->operation);
    }

    public function test_dropped_table_written_by_base_release_reaches_db003(): void
    {
        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->dropLegacyOrdersMigration(),
            file: 'database/migrations/drop_legacy_orders.php',
        );

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $this->baseInsert(),
            file: 'app/Services/LegacyOrderCreator.php',
        );

        $findings = $this->app
            ->make(CompatibilityEngine::class)
            ->analyze($delta, $snapshot);

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB003', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('legacy_orders', $finding->table);
        $this->assertSame('insert', $finding->usage?->operation);
    }

    public function test_db003_can_be_disabled_through_configuration(): void
    {
        config()->set('release-guard.rules.DB003', false);

        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->dropLegacyOrdersMigration(),
            file: 'database/migrations/drop_legacy_orders.php',
        );

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $this->baseGet(),
            file: 'app/Services/LegacyOrderReader.php',
        );

        $findings = $this->app
            ->make(CompatibilityEngine::class)
            ->analyze($delta, $snapshot);

        $this->assertSame([], $findings);
    }

    private function dropLegacyOrdersMigration(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::drop('legacy_orders');
    }
};
PHP;
    }

    private function baseGet(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('legacy_orders')->get();
PHP;
    }

    private function baseInsert(): string
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

    public function test_drop_if_exists_used_by_base_release_reaches_db003(): void
    {
        $migration = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('legacy_orders');
    }
};
PHP;

        $delta = (new MigrationAnalyzer())->analyze(
            source: $migration,
            file: 'database/migrations/drop_legacy_orders.php',
        );

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $this->baseGet(),
            file: 'app/Services/LegacyOrderReader.php',
        );

        $findings = $this->app
            ->make(CompatibilityEngine::class)
            ->analyze($delta, $snapshot);

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB003', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('legacy_orders', $finding->table);
        $this->assertTrue($finding->change->ifExists);
    }

}
