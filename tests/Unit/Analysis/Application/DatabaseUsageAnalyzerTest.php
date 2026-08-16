<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Analysis\Application;

use Hopheartsceo\ReleaseGuard\Analysis\Application\DatabaseUsageAnalyzer;
use Hopheartsceo\ReleaseGuard\Domain\Application\WriteUsage;
use PHPUnit\Framework\TestCase;

final class DatabaseUsageAnalyzerTest extends TestCase
{
    public function test_it_detects_a_literal_query_builder_insert(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')->insert([
    'name' => $name,
    'email' => $email,
]);
PHP;

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/UserCreator.php',
        );

        $usages = $snapshot->usages();

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(WriteUsage::class, $usages[0]);

        $usage = $usages[0];

        $this->assertSame('users', $usage->table);
        $this->assertSame('insert', $usage->operation);
        $this->assertSame(
            ['name', 'email'],
            $usage->columns,
        );
        $this->assertSame(
            'app/Services/UserCreator.php',
            $usage->file,
        );
        $this->assertSame(5, $usage->line);
    }

    public function test_it_preserves_an_empty_literal_insert_payload(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('audit_logs')->insert([]);
PHP;

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/AuditWriter.php',
        );

        $usages = $snapshot->usages();

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(WriteUsage::class, $usages[0]);
        $this->assertSame('audit_logs', $usages[0]->table);
        $this->assertSame([], $usages[0]->columns);
    }

    public function test_unrelated_get_method_is_ignored(): void
    {
        $source = <<<'PHP'
<?php

$repository->get();
PHP;

        $snapshot = (new DatabaseUsageAnalyzer())->analyze(
            source: $source,
            file: 'app/Services/UserLookup.php',
        );

        $this->assertSame([], $snapshot->usages());
    }
}
