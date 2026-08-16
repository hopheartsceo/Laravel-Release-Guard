<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Feature;

use Hopheartsceo\ReleaseGuard\Analysis\Application\DatabaseUsageAnalyzer;
use Hopheartsceo\ReleaseGuard\Analysis\Migrations\MigrationAnalyzer;
use Hopheartsceo\ReleaseGuard\Compatibility\CompatibilityEngine;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Tests\TestCase;

final class RenamedColumnStillReferencedIntegrationTest extends TestCase
{
    public function test_renamed_column_used_by_base_query_reaches_db002(): void
    {
        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->renamePhoneMigration(),
            file: 'database/migrations/rename_phone_on_users.php',
        );

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $this->baseWhereQuery(),
            file: 'app/Services/UserLookup.php',
        );

        $findings = $this->app
            ->make(CompatibilityEngine::class)
            ->analyze($delta, $snapshot);

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB002', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('users', $finding->table);
        $this->assertSame('phone', $finding->column);
        $this->assertSame('where', $finding->usage?->operation);
    }

    public function test_renamed_column_written_by_base_insert_reaches_db002(): void
    {
        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->renamePhoneMigration(),
            file: 'database/migrations/rename_phone_on_users.php',
        );

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $this->baseInsert(),
            file: 'app/Services/UserCreator.php',
        );

        $findings = $this->app
            ->make(CompatibilityEngine::class)
            ->analyze($delta, $snapshot);

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB002', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('phone', $finding->column);
        $this->assertSame('insert', $finding->usage?->operation);
    }

    public function test_base_usage_of_new_column_does_not_trigger_db002(): void
    {
        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->renamePhoneMigration(),
            file: 'database/migrations/rename_phone_on_users.php',
        );

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $this->baseNewColumnQuery(),
            file: 'app/Services/UserLookup.php',
        );

        $findings = $this->app
            ->make(CompatibilityEngine::class)
            ->analyze($delta, $snapshot);

        $this->assertSame([], $findings);
    }

    public function test_db002_can_be_disabled_through_configuration(): void
    {
        config()->set('release-guard.rules.DB002', false);

        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->renamePhoneMigration(),
            file: 'database/migrations/rename_phone_on_users.php',
        );

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $this->baseWhereQuery(),
            file: 'app/Services/UserLookup.php',
        );

        $findings = $this->app
            ->make(CompatibilityEngine::class)
            ->analyze($delta, $snapshot);

        $this->assertSame([], $findings);
    }

    private function renamePhoneMigration(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('phone', 'mobile');
        });
    }
};
PHP;
    }

    private function baseWhereQuery(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')
    ->where('phone', $phone)
    ->first();
PHP;
    }

    private function baseInsert(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')->insert([
    'name' => $name,
    'phone' => $phone,
]);
PHP;
    }

    private function baseNewColumnQuery(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')
    ->where('mobile', $mobile)
    ->first();
PHP;
    }
}
