<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Analysis\Application;

use Hopheartsceo\ReleaseGuard\Analysis\Application\DatabaseUsageAnalyzer;
use Hopheartsceo\ReleaseGuard\Domain\Application\ColumnUsage;
use Hopheartsceo\ReleaseGuard\Domain\Application\TableUsage;
use Hopheartsceo\ReleaseGuard\Domain\Application\WriteUsage;
use PHPUnit\Framework\TestCase;

final class DatabaseUsageAnalyzerExpandedQueryBuilderTest extends TestCase
{
    public function test_common_filter_and_order_columns_are_detected(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('orders')
    ->whereIn('status', ['pending'])
    ->orderBy('created_at')
    ->groupBy('customer_id', 'status')
    ->get();
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/OrderLookup.php',
        )->usages();

        $actual = array_map(
            static fn (ColumnUsage $usage): array => [
                $usage->column,
                $usage->operation,
            ],
            $usages,
        );

        $this->assertCount(4, $actual);
        $this->assertContains(['status', 'whereIn'], $actual);
        $this->assertContains(['created_at', 'orderBy'], $actual);
        $this->assertContains(['customer_id', 'groupBy'], $actual);
        $this->assertContains(['status', 'groupBy'], $actual);
    }

    public function test_select_array_columns_are_detected(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')
    ->select(['id', 'email'])
    ->first();
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/UserReader.php',
        )->usages();

        $this->assertCount(2, $usages);
        $this->assertSame('id', $usages[0]->column);
        $this->assertSame('email', $usages[1]->column);
    }

    public function test_get_column_array_is_detected(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')->get(['id', 'email']);
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/UserReader.php',
        )->usages();

        $this->assertCount(2, $usages);
        $this->assertSame('id', $usages[0]->column);
        $this->assertSame('get', $usages[0]->operation);
        $this->assertSame('email', $usages[1]->column);
    }

    public function test_pluck_detects_value_and_key_columns(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')->pluck('email', 'id');
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/UserReader.php',
        )->usages();

        $this->assertCount(2, $usages);
        $this->assertSame('email', $usages[0]->column);
        $this->assertSame('id', $usages[1]->column);
    }

    public function test_update_payload_is_preserved_as_write_usage(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')
    ->where('id', $id)
    ->update([
        'status' => 'active',
        'updated_at' => now(),
    ]);
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/UserWriter.php',
        )->usages();

        $writes = array_values(array_filter(
            $usages,
            static fn ($usage): bool =>
                $usage instanceof WriteUsage,
        ));

        $this->assertCount(1, $writes);
        $this->assertSame('users', $writes[0]->table);
        $this->assertSame('update', $writes[0]->operation);
        $this->assertSame(
            ['status', 'updated_at'],
            $writes[0]->columns,
        );
        $this->assertNull($writes[0]->reason);
    }

    public function test_insert_variants_are_preserved_as_write_usages(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')->insertOrIgnore([
    'email' => $email,
]);

DB::table('users')->insertGetId([
    'email' => $email,
]);
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/UserWriter.php',
        )->usages();

        $this->assertCount(2, $usages);
        $this->assertSame(
            'insertOrIgnore',
            $usages[0]->operation,
        );
        $this->assertSame(
            'insertGetId',
            $usages[1]->operation,
        );
        $this->assertSame(['email'], $usages[0]->columns);
        $this->assertSame(['email'], $usages[1]->columns);
    }

    public function test_multi_row_insert_columns_are_deterministic(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')->insert([
    [
        'name' => 'One',
        'email' => 'one@example.test',
    ],
    [
        'name' => 'Two',
        'email' => 'two@example.test',
    ],
]);
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/UserWriter.php',
        )->usages();

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(
            WriteUsage::class,
            $usages[0],
        );
        $this->assertSame(
            ['name', 'email'],
            $usages[0]->columns,
        );
        $this->assertNull($usages[0]->reason);
    }

    public function test_dynamic_column_still_preserves_literal_table_usage(): void
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
        $this->assertInstanceOf(
            TableUsage::class,
            $usages[0],
        );
        $this->assertSame('users', $usages[0]->table);
        $this->assertSame('first', $usages[0]->operation);
    }
}
