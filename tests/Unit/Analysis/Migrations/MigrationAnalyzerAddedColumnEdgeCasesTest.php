<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Analysis\Migrations;

use Hopheartsceo\ReleaseGuard\Analysis\Migrations\MigrationAnalyzer;
use Hopheartsceo\ReleaseGuard\Domain\Schema\AddedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\UnanalyzableMigrationOperation;
use PHPUnit\Framework\TestCase;

final class MigrationAnalyzerAddedColumnEdgeCasesTest extends TestCase
{
    public function test_it_detects_foreign_id_with_nullable_modifier(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::table('orders', function (Blueprint $table) {
    $table->foreignId('assigned_to')->nullable();
});
PHP;

        $changes = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/add_assigned_to_to_orders.php',
        )->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(AddedColumn::class, $changes[0]);

        $change = $changes[0];

        $this->assertSame('orders', $change->table);
        $this->assertSame('assigned_to', $change->column);
        $this->assertSame('foreignId', $change->type);
        $this->assertTrue($change->nullable);
        $this->assertFalse($change->hasDefault);
    }

    public function test_it_detects_multiple_modifiers_in_one_chain(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::table('events', function (Blueprint $table) {
    $table->timestamp('published_at')->nullable()->useCurrent();
});
PHP;

        $changes = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/add_published_at_to_events.php',
        )->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(AddedColumn::class, $changes[0]);

        $change = $changes[0];

        $this->assertTrue($change->nullable);
        $this->assertTrue($change->hasDefault);
        $this->assertTrue($change->usesCurrent);
    }

    public function test_dynamic_added_column_name_is_reported_as_unanalyzable(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

$column = resolveColumnName();

Schema::table('users', function (Blueprint $table) use ($column) {
    $table->string($column);
});
PHP;

        $changes = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/add_dynamic_column_to_users.php',
        )->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(
            UnanalyzableMigrationOperation::class,
            $changes[0],
        );

        $change = $changes[0];

        $this->assertSame('addColumn', $change->operation);
        $this->assertSame('users', $change->table);
        $this->assertNull($change->column);
        $this->assertSame('dynamic_column', $change->reason);
    }

    public function test_dynamic_table_for_added_column_is_reported_as_unanalyzable(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

$tableName = resolveTableName();

Schema::table($tableName, function (Blueprint $table) {
    $table->string('country_code');
});
PHP;

        $changes = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/add_country_code_to_dynamic_table.php',
        )->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(
            UnanalyzableMigrationOperation::class,
            $changes[0],
        );

        $change = $changes[0];

        $this->assertSame('addColumn', $change->operation);
        $this->assertNull($change->table);
        $this->assertSame('country_code', $change->column);
        $this->assertSame('dynamic_table', $change->reason);
    }

    public function test_nullable_false_does_not_make_the_column_nullable(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::table('users', function (Blueprint $table) {
    $table->string('country_code')->nullable(false);
});
PHP;

        $changes = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/add_country_code_to_users.php',
        )->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(AddedColumn::class, $changes[0]);
        $this->assertFalse($changes[0]->nullable);
    }

    public function test_dynamic_nullable_modifier_is_reported_as_unanalyzable(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

$nullable = determineNullable();

Schema::table('users', function (Blueprint $table) use ($nullable) {
    $table->string('country_code')->nullable($nullable);
});
PHP;

        $changes = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/add_country_code_to_users.php',
        )->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(
            UnanalyzableMigrationOperation::class,
            $changes[0],
        );

        $change = $changes[0];

        $this->assertSame('addColumn', $change->operation);
        $this->assertSame('users', $change->table);
        $this->assertSame('country_code', $change->column);
        $this->assertSame(
            'dynamic_nullable_modifier',
            $change->reason,
        );
    }

}
