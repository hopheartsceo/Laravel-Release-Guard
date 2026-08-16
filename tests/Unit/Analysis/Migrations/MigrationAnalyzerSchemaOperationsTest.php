<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Analysis\Migrations;

use Hopheartsceo\ReleaseGuard\Analysis\Migrations\MigrationAnalyzer;
use Hopheartsceo\ReleaseGuard\Domain\Schema\DroppedTable;
use Hopheartsceo\ReleaseGuard\Domain\Schema\RenamedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\RenamedTable;
use PHPUnit\Framework\TestCase;

final class MigrationAnalyzerSchemaOperationsTest extends TestCase
{
    public function test_it_detects_a_literal_renamed_column(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::table('users', function (Blueprint $table) {
    $table->renameColumn('phone', 'mobile');
});
PHP;

        $delta = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/rename_phone_to_mobile.php',
        );

        $changes = $delta->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(RenamedColumn::class, $changes[0]);
        $this->assertSame('users', $changes[0]->table);
        $this->assertSame('phone', $changes[0]->from);
        $this->assertSame('mobile', $changes[0]->to);
        $this->assertSame(
            'database/migrations/rename_phone_to_mobile.php',
            $changes[0]->file,
        );
    }

    public function test_it_detects_schema_drop(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\Schema;

Schema::drop('legacy_orders');
PHP;

        $delta = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/drop_legacy_orders.php',
        );

        $changes = $delta->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(DroppedTable::class, $changes[0]);
        $this->assertSame('legacy_orders', $changes[0]->table);
        $this->assertFalse($changes[0]->ifExists);
    }

    public function test_it_detects_schema_drop_if_exists(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\Schema;

Schema::dropIfExists('legacy_orders');
PHP;

        $delta = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/drop_legacy_orders.php',
        );

        $changes = $delta->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(DroppedTable::class, $changes[0]);
        $this->assertSame('legacy_orders', $changes[0]->table);
        $this->assertTrue($changes[0]->ifExists);
    }

    public function test_it_detects_schema_rename(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\Schema;

Schema::rename('customers', 'clients');
PHP;

        $delta = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/rename_customers_to_clients.php',
        );

        $changes = $delta->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(RenamedTable::class, $changes[0]);
        $this->assertSame('customers', $changes[0]->from);
        $this->assertSame('clients', $changes[0]->to);
    }
}
