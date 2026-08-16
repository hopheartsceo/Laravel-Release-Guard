<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Feature;

use Hopheartsceo\ReleaseGuard\Analysis\Application\DatabaseUsageAnalyzer;
use Hopheartsceo\ReleaseGuard\Analysis\Migrations\MigrationAnalyzer;
use Hopheartsceo\ReleaseGuard\Compatibility\CompatibilityEngine;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Tests\TestCase;

final class RequiredColumnBreaksBaseWritesIntegrationTest extends TestCase
{
    public function test_required_added_column_breaking_base_insert_reaches_db005(): void
    {
        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->requiredColumnMigration(),
            file: 'database/migrations/add_country_code_to_users.php',
        );

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $this->baseUserInsert(),
            file: 'app/Services/UserCreator.php',
        );

        $findings = $this->app
            ->make(CompatibilityEngine::class)
            ->analyze($delta, $snapshot);

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB005', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('users', $finding->table);
        $this->assertSame('country_code', $finding->column);
        $this->assertSame('insert', $finding->usage?->operation);
    }

    public function test_nullable_added_column_does_not_break_base_insert(): void
    {
        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->nullableColumnMigration(),
            file: 'database/migrations/add_country_code_to_users.php',
        );

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $this->baseUserInsert(),
            file: 'app/Services/UserCreator.php',
        );

        $findings = $this->app
            ->make(CompatibilityEngine::class)
            ->analyze($delta, $snapshot);

        $this->assertSame([], $findings);
    }

    public function test_db005_can_be_disabled_through_configuration(): void
    {
        config()->set('release-guard.rules.DB005', false);

        $delta = (new MigrationAnalyzer())->analyze(
            source: $this->requiredColumnMigration(),
            file: 'database/migrations/add_country_code_to_users.php',
        );

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $this->baseUserInsert(),
            file: 'app/Services/UserCreator.php',
        );

        $findings = $this->app
            ->make(CompatibilityEngine::class)
            ->analyze($delta, $snapshot);

        $this->assertSame([], $findings);
    }

    private function requiredColumnMigration(): string
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
            $table->string('country_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('country_code');
        });
    }
};
PHP;
    }

    private function nullableColumnMigration(): string
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
            $table->string('country_code')->nullable();
        });
    }
};
PHP;
    }

    private function baseUserInsert(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')->insert([
    'name' => $name,
    'email' => $email,
]);
PHP;
    }
}
