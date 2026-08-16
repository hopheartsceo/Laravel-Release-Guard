<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Analysis\Migrations;

use Hopheartsceo\ReleaseGuard\Analysis\Migrations\MigrationAnalyzer;
use Hopheartsceo\ReleaseGuard\Domain\Schema\DroppedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\UnanalyzableMigrationOperation;
use PHPUnit\Framework\TestCase;

final class MigrationAnalyzerTest extends TestCase
{
    public function test_it_detects_a_literal_dropped_column_inside_schema_table(): void
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
};
PHP;

        $delta = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/2026_08_16_000000_drop_phone_from_users.php',
        );

        $changes = $delta->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(DroppedColumn::class, $changes[0]);

        $change = $changes[0];

        $this->assertSame('users', $change->table);
        $this->assertSame('phone', $change->column);
        $this->assertSame(
            'database/migrations/2026_08_16_000000_drop_phone_from_users.php',
            $change->file,
        );
        $this->assertSame(12, $change->line);
    }

    public function test_it_does_not_treat_drop_column_inside_schema_create_as_a_drop_from_the_existing_schema(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::create('users', function (Blueprint $table) {
    $table->dropColumn('phone');
});
PHP;

        $delta = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/create_users_table.php',
        );

        $this->assertSame([], $delta->changes());
    }

    public function test_it_reports_an_unanalyzable_operation_when_schema_table_is_dynamic(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

$tableName = resolveTableName();

Schema::table($tableName, function (Blueprint $table) {
    $table->dropColumn('phone');
});
PHP;

        $delta = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/dynamic_table.php',
        );

        $changes = $delta->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(
            UnanalyzableMigrationOperation::class,
            $changes[0],
        );

        $change = $changes[0];

        $this->assertSame('dropColumn', $change->operation);
        $this->assertNull($change->table);
        $this->assertSame('phone', $change->column);
        $this->assertSame('dynamic_table', $change->reason);
        $this->assertSame(
            'database/migrations/dynamic_table.php',
            $change->file,
        );
    }

    public function test_it_reports_an_unanalyzable_operation_when_drop_column_is_dynamic(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

$column = resolveColumnName();

Schema::table('users', function (Blueprint $table) use ($column) {
    $table->dropColumn($column);
});
PHP;

        $delta = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/dynamic_column.php',
        );

        $changes = $delta->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(
            UnanalyzableMigrationOperation::class,
            $changes[0],
        );

        $change = $changes[0];

        $this->assertSame('dropColumn', $change->operation);
        $this->assertSame('users', $change->table);
        $this->assertNull($change->column);
        $this->assertSame('dynamic_column', $change->reason);
        $this->assertSame(
            'database/migrations/dynamic_column.php',
            $change->file,
        );
    }

    public function test_it_tracks_the_actual_blueprint_parameter_name(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::table('users', function (Blueprint $blueprint) {
    $blueprint->dropColumn('phone');
});
PHP;

        $delta = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/drop_phone.php',
        );

        $changes = $delta->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(DroppedColumn::class, $changes[0]);
        $this->assertSame('users', $changes[0]->table);
        $this->assertSame('phone', $changes[0]->column);
    }
}
