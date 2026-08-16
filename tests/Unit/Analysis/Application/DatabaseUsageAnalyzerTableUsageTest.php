<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Analysis\Application;

use Hopheartsceo\ReleaseGuard\Analysis\Application\DatabaseUsageAnalyzer;
use Hopheartsceo\ReleaseGuard\Domain\Application\TableUsage;
use PHPUnit\Framework\TestCase;

final class DatabaseUsageAnalyzerTableUsageTest extends TestCase
{
    public function test_plain_get_creates_literal_table_usage(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('legacy_orders')->get();
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/LegacyOrderReader.php',
        )->usages();

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(TableUsage::class, $usages[0]);

        $usage = $usages[0];

        $this->assertSame('legacy_orders', $usage->table);
        $this->assertSame('get', $usage->operation);
        $this->assertSame(
            'app/Services/LegacyOrderReader.php',
            $usage->file,
        );
        $this->assertSame(5, $usage->line);
    }

    public function test_dynamic_table_does_not_create_definite_table_usage(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

$table = resolveTable();

DB::table($table)->get();
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/DynamicReader.php',
        )->usages();

        $this->assertSame([], $usages);
    }

    public function test_where_chain_does_not_add_duplicate_table_usage(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('legacy_orders')
    ->where('status', 'pending')
    ->get();
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/LegacyOrderReader.php',
        )->usages();

        $this->assertCount(1, $usages);
        $this->assertSame('status', $usages[0]->column);
        $this->assertSame('where', $usages[0]->operation);
    }

    public function test_select_chain_does_not_add_duplicate_table_usage(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('legacy_orders')
    ->select('id', 'status')
    ->get();
PHP;

        $usages = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/LegacyOrderReader.php',
        )->usages();

        $this->assertCount(2, $usages);
        $this->assertSame('id', $usages[0]->column);
        $this->assertSame('status', $usages[1]->column);
    }
}
