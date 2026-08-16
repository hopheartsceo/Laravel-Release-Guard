<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Analysis\Migrations;

use Hopheartsceo\ReleaseGuard\Analysis\Migrations\MigrationAnalyzer;
use Hopheartsceo\ReleaseGuard\Domain\Schema\AddedColumn;
use PHPUnit\Framework\TestCase;

final class MigrationAnalyzerAddedColumnsTest extends TestCase
{
    public function test_it_detects_a_required_added_column(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::table('users', function (Blueprint $table) {
    $table->string('country_code');
});
PHP;

        $changes = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/add_country_code_to_users.php',
        )->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(AddedColumn::class, $changes[0]);

        $change = $changes[0];

        $this->assertSame('users', $change->table);
        $this->assertSame('country_code', $change->column);
        $this->assertSame('string', $change->type);
        $this->assertFalse($change->nullable);
        $this->assertFalse($change->hasDefault);
        $this->assertFalse($change->usesCurrent);
    }

    public function test_it_detects_nullable_modifier(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::table('users', function (Blueprint $table) {
    $table->string('country_code')->nullable();
});
PHP;

        $changes = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/add_country_code_to_users.php',
        )->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(AddedColumn::class, $changes[0]);

        $change = $changes[0];

        $this->assertTrue($change->nullable);
        $this->assertFalse($change->hasDefault);
        $this->assertFalse($change->usesCurrent);
    }

    public function test_it_detects_default_modifier(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::table('users', function (Blueprint $table) {
    $table->string('country_code')->default('SA');
});
PHP;

        $changes = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/add_country_code_to_users.php',
        )->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(AddedColumn::class, $changes[0]);

        $change = $changes[0];

        $this->assertFalse($change->nullable);
        $this->assertTrue($change->hasDefault);
        $this->assertFalse($change->usesCurrent);
    }

    public function test_it_detects_use_current_as_a_database_default(): void
    {
        $source = <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::table('events', function (Blueprint $table) {
    $table->timestamp('published_at')->useCurrent();
});
PHP;

        $changes = (new MigrationAnalyzer())->analyze(
            source: $source,
            file: 'database/migrations/add_published_at_to_events.php',
        )->changes();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(AddedColumn::class, $changes[0]);

        $change = $changes[0];

        $this->assertSame('events', $change->table);
        $this->assertSame('published_at', $change->column);
        $this->assertSame('timestamp', $change->type);
        $this->assertFalse($change->nullable);
        $this->assertTrue($change->hasDefault);
        $this->assertTrue($change->usesCurrent);
    }
}
