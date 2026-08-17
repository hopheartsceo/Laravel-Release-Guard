<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Analysis\Application;

use Hopheartsceo\ReleaseGuard\Analysis\Application\DatabaseUsageAnalyzer;
use Hopheartsceo\ReleaseGuard\Domain\Application\ColumnUsage;
use Hopheartsceo\ReleaseGuard\Domain\Application\TableUsage;
use PHPUnit\Framework\TestCase;

final class DatabaseUsageAnalyzerColumnUsageTest extends TestCase
{
    public function test_it_detects_literal_where_column_usage(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')->where('phone', $phone)->first();
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/UserLookup.php',
        )->usages();

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(ColumnUsage::class, $usages[0]);

        $usage = $usages[0];

        $this->assertSame('users', $usage->table);
        $this->assertSame('phone', $usage->column);
        $this->assertSame('where', $usage->operation);
        $this->assertSame('app/Services/UserLookup.php', $usage->file);
        $this->assertSame(5, $usage->line);
    }

    public function test_it_detects_each_literal_select_column(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')
    ->select('id', 'phone')
    ->get();
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/UserReader.php',
        )->usages();

        $this->assertCount(2, $usages);

        $this->assertInstanceOf(ColumnUsage::class, $usages[0]);
        $this->assertInstanceOf(ColumnUsage::class, $usages[1]);

        $this->assertSame('users', $usages[0]->table);
        $this->assertSame('id', $usages[0]->column);
        $this->assertSame('select', $usages[0]->operation);

        $this->assertSame('users', $usages[1]->table);
        $this->assertSame('phone', $usages[1]->column);
        $this->assertSame('select', $usages[1]->operation);
    }

    public function test_plain_get_does_not_create_column_usage(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')->get();
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/UserReader.php',
        )->usages();

        $columnUsages = array_values(array_filter(
            $usages,
            static fn ($usage): bool => $usage instanceof ColumnUsage,
        ));

        $this->assertSame([], $columnUsages);
    }

    public function test_it_tracks_columns_through_a_deep_query_builder_chain(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')
    ->where('active', true)
    ->where('phone', $phone)
    ->select('id', 'phone')
    ->first();
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/UserLookup.php',
        )->usages();

        $this->assertCount(4, $usages);

        $actual = array_map(
            static fn (ColumnUsage $usage): array => [
                $usage->table,
                $usage->column,
                $usage->operation,
            ],
            $usages,
        );

        $this->assertContains(
            ['users', 'active', 'where'],
            $actual,
        );

        $this->assertContains(
            ['users', 'phone', 'where'],
            $actual,
        );

        $this->assertContains(
            ['users', 'id', 'select'],
            $actual,
        );

        $this->assertContains(
            ['users', 'phone', 'select'],
            $actual,
        );
    }

    public function test_dynamic_table_does_not_create_definite_column_usage(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

$table = resolveTable();

DB::table($table)
    ->where('phone', $phone)
    ->first();
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/DynamicLookup.php',
        )->usages();

        $this->assertSame([], $usages);
    }

    public function test_dynamic_where_column_preserves_only_definite_table_usage(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

$column = resolveColumn();

DB::table('users')
    ->where($column, $value)
    ->first();
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/DynamicLookup.php',
        )->usages();

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(TableUsage::class, $usages[0]);
        $this->assertSame('users', $usages[0]->table);
        $this->assertSame('first', $usages[0]->operation);
    }

    public function test_literal_select_columns_remain_known_when_another_column_is_dynamic(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

$column = resolveColumn();

DB::table('users')
    ->select('id', $column)
    ->get();
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/DynamicLookup.php',
        )->usages();

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(ColumnUsage::class, $usages[0]);
        $this->assertSame('users', $usages[0]->table);
        $this->assertSame('id', $usages[0]->column);
        $this->assertSame('select', $usages[0]->operation);
    }

}
