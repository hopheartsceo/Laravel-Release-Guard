<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Analysis\Migrations;

use Hopheartsceo\ReleaseGuard\Analysis\Migrations\MigrationAnalyzer;
use Hopheartsceo\ReleaseGuard\Domain\Schema\UnanalyzableMigrationOperation;
use PHPUnit\Framework\TestCase;

final class MigrationAnalyzerUnknownOperationsTest extends TestCase
{
    public function test_dynamic_rename_column_source_is_reported_as_unanalyzable(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

$column = resolveColumn();

Schema::table('users', function (Blueprint $table) use ($column) {
    $table->renameColumn($column, 'mobile');
});
PHP;

        $changes = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/dynamic_rename_column.php',
        )->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(
            UnanalyzableMigrationOperation::class,
            $changes[0],
        );

        $this->assertSame('renameColumn', $changes[0]->operation);
        $this->assertSame('users', $changes[0]->table);
        $this->assertNull($changes[0]->column);
        $this->assertSame('dynamic_source_column', $changes[0]->reason);
    }

    public function test_dynamic_schema_drop_table_is_reported_as_unanalyzable(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\Schema;

$table = resolveTable();

Schema::drop($table);
PHP;

        $changes = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/dynamic_drop.php',
        )->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(
            UnanalyzableMigrationOperation::class,
            $changes[0],
        );

        $this->assertSame('drop', $changes[0]->operation);
        $this->assertNull($changes[0]->table);
        $this->assertNull($changes[0]->column);
        $this->assertSame('dynamic_table', $changes[0]->reason);
    }

    public function test_dynamic_schema_drop_if_exists_table_is_reported_as_unanalyzable(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\Schema;

$table = resolveTable();

Schema::dropIfExists($table);
PHP;

        $changes = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/dynamic_drop_if_exists.php',
        )->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(
            UnanalyzableMigrationOperation::class,
            $changes[0],
        );

        $this->assertSame('dropIfExists', $changes[0]->operation);
        $this->assertNull($changes[0]->table);
        $this->assertSame('dynamic_table', $changes[0]->reason);
    }

    public function test_raw_db_statement_is_reported_as_unanalyzable(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::statement(
    'ALTER TABLE users DROP COLUMN legacy_status'
);
PHP;

        $changes = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/raw_statement.php',
        )->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(
            UnanalyzableMigrationOperation::class,
            $changes[0],
        );

        $this->assertSame(
            'statement',
            $changes[0]->operation,
        );
        $this->assertNull($changes[0]->table);
        $this->assertNull($changes[0]->column);
        $this->assertSame(
            'raw_sql',
            $changes[0]->reason,
        );
    }


    public function test_dynamic_schema_rename_target_is_reported_as_unanalyzable(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\Schema;

$target = resolveTargetTable();

Schema::rename('customers', $target);
PHP;

        $changes = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/dynamic_rename_table.php',
        )->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(
            UnanalyzableMigrationOperation::class,
            $changes[0],
        );

        $this->assertSame('rename', $changes[0]->operation);
        $this->assertSame('customers', $changes[0]->table);
        $this->assertNull($changes[0]->column);
        $this->assertSame('dynamic_target_table', $changes[0]->reason);
    }
}
