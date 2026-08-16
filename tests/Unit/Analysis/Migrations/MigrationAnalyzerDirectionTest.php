<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Analysis\Migrations;

use Hopheartsceo\ReleaseGuard\Analysis\Migrations\MigrationAnalyzer;
use Hopheartsceo\ReleaseGuard\Domain\Schema\DroppedColumn;
use PHPUnit\Framework\TestCase;

final class MigrationAnalyzerDirectionTest extends TestCase
{
    public function test_it_ignores_operations_inside_down_on_anonymous_migrations(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
PHP;

        $changes = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/example.php',
        )->changes();

        $this->assertSame([], $changes);
    }

    public function test_it_analyzes_up_but_not_down(): void
    {
        $source = <<<'PHP'
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
            $table->dropColumn('email');
        });
    }
};
PHP;

        $changes = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/example.php',
        )->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(DroppedColumn::class, $changes[0]);
        $this->assertSame('users', $changes[0]->table);
        $this->assertSame('phone', $changes[0]->column);
    }

    public function test_it_ignores_down_on_named_migration_classes(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DropPhoneFromUsers extends Migration
{
    public function up(): void
    {
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
}
PHP;

        $changes = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/example.php',
        )->changes();

        $this->assertSame([], $changes);
    }
}
