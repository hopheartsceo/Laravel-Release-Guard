<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Feature;

use Hopheartsceo\ReleaseGuard\Analysis\Application\DatabaseUsageAnalyzer;
use Hopheartsceo\ReleaseGuard\Analysis\Migrations\MigrationAnalyzer;
use Hopheartsceo\ReleaseGuard\Compatibility\CompatibilityEngine;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Tests\TestCase;

final class DroppedColumnStillReferencedIntegrationTest extends TestCase
{
    public function test_dropped_column_used_by_base_where_query_reaches_db001(): void
    {
        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->dropPhoneMigration(),
            file: 'database/migrations/drop_phone_from_users.php',
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

        $this->assertSame('DB001', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('users', $finding->table);
        $this->assertSame('phone', $finding->column);
        $this->assertSame('where', $finding->usage?->operation);
    }

    public function test_dropped_column_used_by_base_select_reaches_db001(): void
    {
        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->dropPhoneMigration(),
            file: 'database/migrations/drop_phone_from_users.php',
        );

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $this->baseSelectQuery(),
            file: 'app/Services/UserReader.php',
        );

        $findings = $this->app
            ->make(CompatibilityEngine::class)
            ->analyze($delta, $snapshot);

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB001', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('users', $finding->table);
        $this->assertSame('phone', $finding->column);
        $this->assertSame('select', $finding->usage?->operation);
    }

    public function test_unrelated_base_column_does_not_trigger_db001(): void
    {
        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->dropPhoneMigration(),
            file: 'database/migrations/drop_phone_from_users.php',
        );

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $this->baseEmailQuery(),
            file: 'app/Services/UserLookup.php',
        );

        $findings = $this->app
            ->make(CompatibilityEngine::class)
            ->analyze($delta, $snapshot);

        $this->assertSame([], $findings);
    }

    private function dropPhoneMigration(): string
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
            $table->dropColumn('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable();
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

    private function baseSelectQuery(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')
    ->select('phone')
    ->first();
PHP;
    }

    private function baseEmailQuery(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')
    ->where('email', $email)
    ->first();
PHP;
    }

    public function test_dropped_column_written_by_base_insert_reaches_db001(): void
    {
        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->dropPhoneMigration(),
            file: 'database/migrations/drop_phone_from_users.php',
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

        $this->assertSame('DB001', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('users', $finding->table);
        $this->assertSame('phone', $finding->column);
        $this->assertSame('insert', $finding->usage?->operation);
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

}
