<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Analysis\Application;

use Hopheartsceo\ReleaseGuard\Analysis\Application\DatabaseUsageAnalyzer;
use Hopheartsceo\ReleaseGuard\Domain\Application\WriteUsage;
use PHPUnit\Framework\TestCase;

final class DatabaseUsageAnalyzerUnknownWritesTest extends TestCase
{
    public function test_dynamic_table_insert_is_preserved_as_unknown_write(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

$table = resolveTable();

DB::table($table)->insert([
    'name' => $name,
]);
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/DynamicWriter.php',
        )->usages();

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(WriteUsage::class, $usages[0]);

        $usage = $usages[0];

        $this->assertNull($usage->table);
        $this->assertSame('insert', $usage->operation);
        $this->assertSame(['name'], $usage->columns);
        $this->assertSame('dynamic_table', $usage->reason);
    }

    public function test_dynamic_insert_payload_is_preserved_as_unknown_write(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

$payload = buildPayload();

DB::table('users')->insert($payload);
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/UserWriter.php',
        )->usages();

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(WriteUsage::class, $usages[0]);

        $usage = $usages[0];

        $this->assertSame('users', $usage->table);
        $this->assertSame('insert', $usage->operation);
        $this->assertNull($usage->columns);
        $this->assertSame('dynamic_payload', $usage->reason);
    }

    public function test_dynamic_array_key_is_preserved_as_unknown_write(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

$column = resolveColumn();

DB::table('users')->insert([
    $column => $value,
    'email' => $email,
]);
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/UserWriter.php',
        )->usages();

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(WriteUsage::class, $usages[0]);

        $usage = $usages[0];

        $this->assertSame('users', $usage->table);
        $this->assertNull($usage->columns);
        $this->assertSame('dynamic_column_key', $usage->reason);
    }

    public function test_literal_insert_has_no_unknown_reason(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')->insert([
    'name' => $name,
    'email' => $email,
]);
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/UserWriter.php',
        )->usages();

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(WriteUsage::class, $usages[0]);

        $this->assertSame(['name', 'email'], $usages[0]->columns);
        $this->assertNull($usages[0]->reason);
    }

    public function test_dynamic_table_and_payload_are_preserved_together(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

$table = resolveTable();
$payload = buildPayload();

DB::table($table)->insert($payload);
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/DynamicWriter.php',
        )->usages();

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(WriteUsage::class, $usages[0]);

        $usage = $usages[0];

        $this->assertNull($usage->table);
        $this->assertNull($usage->columns);
        $this->assertSame(
            'dynamic_table_and_payload',
            $usage->reason,
        );
    }

    public function test_dynamic_table_and_column_key_are_preserved_together(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

$table = resolveTable();
$column = resolveColumn();

DB::table($table)->insert([
    $column => $value,
]);
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/DynamicWriter.php',
        )->usages();

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(WriteUsage::class, $usages[0]);

        $usage = $usages[0];

        $this->assertNull($usage->table);
        $this->assertNull($usage->columns);
        $this->assertSame(
            'dynamic_table_and_column_key',
            $usage->reason,
        );
    }

}
