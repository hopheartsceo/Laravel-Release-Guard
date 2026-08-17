<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Analysis\Application;

use Hopheartsceo\ReleaseGuard\Analysis\Application\EloquentUsageAnalyzer;
use Hopheartsceo\ReleaseGuard\Domain\Application\ColumnUsage;
use Hopheartsceo\ReleaseGuard\Domain\Application\ModelDescriptor;
use Hopheartsceo\ReleaseGuard\Domain\Application\ModelIndex;
use Hopheartsceo\ReleaseGuard\Domain\Application\TableUsage;
use Hopheartsceo\ReleaseGuard\Domain\Application\WriteUsage;
use PHPUnit\Framework\TestCase;

final class EloquentUsageAnalyzerTest extends TestCase
{
    public function test_static_where_uses_model_table(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

User::where('legacy_status', 'active')->get();
PHP;

        $usages = $this->analyze($source);

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(
            ColumnUsage::class,
            $usages[0],
        );
        $this->assertSame('users', $usages[0]->table);
        $this->assertSame(
            'legacy_status',
            $usages[0]->column,
        );
        $this->assertSame(
            'where',
            $usages[0]->operation,
        );
    }

    public function test_query_chain_selects_columns(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

User::query()
    ->select('id', 'email')
    ->get();
PHP;

        $usages = $this->analyze($source);

        $this->assertCount(2, $usages);
        $this->assertSame('id', $usages[0]->column);
        $this->assertSame(
            'email',
            $usages[1]->column,
        );
    }

    public function test_plain_terminal_preserves_table_usage(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

User::first();
PHP;

        $usages = $this->analyze($source);

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(
            TableUsage::class,
            $usages[0],
        );
        $this->assertSame('users', $usages[0]->table);
        $this->assertSame('first', $usages[0]->operation);
    }

    public function test_create_preserves_table_without_inventing_definite_columns(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

User::create([
    'name' => $name,
    'email' => $email,
]);
PHP;

        $usages = $this->analyze($source);

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(
            WriteUsage::class,
            $usages[0],
        );
        $this->assertSame('users', $usages[0]->table);
        $this->assertSame('create', $usages[0]->operation);
        $this->assertNull($usages[0]->columns);
        $this->assertSame(
            'eloquent_model_create_semantics',
            $usages[0]->reason,
        );
    }

    public function test_custom_model_table_is_used(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

User::where('legacy_status', 'active')->get();
PHP;

        $models = new ModelIndex([
            new ModelDescriptor(
                className: 'App\Models\User',
                table: 'legacy_users',
                reason: null,
                file: 'app/Models/User.php',
                line: 7,
            ),
        ]);

        $usages = (new EloquentUsageAnalyzer())
            ->analyze(
                source: $source,
                file: 'app/Services/UserService.php',
                models: $models,
            )
            ->usages();

        $this->assertCount(1, $usages);
        $this->assertSame(
            'legacy_users',
            $usages[0]->table,
        );
    }

    public function test_static_empty_insert_is_ignored_as_noop(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

User::insert([]);
PHP;

        $this->assertSame(
            [],
            $this->analyze($source),
        );
    }

    public function test_query_empty_insert_or_ignore_is_ignored_as_noop(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

User::query()->insertOrIgnore([]);
PHP;

        $this->assertSame(
            [],
            $this->analyze($source),
        );
    }

    public function test_non_query_model_static_chain_is_ignored(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

User::factory()
    ->count(3)
    ->make();
PHP;

        $this->assertSame(
            [],
            $this->analyze($source),
        );
    }

    public function test_known_query_root_still_preserves_table_usage(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

User::query()->count();
PHP;

        $usages = $this->analyze($source);

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(
            TableUsage::class,
            $usages[0],
        );
        $this->assertSame('users', $usages[0]->table);
        $this->assertSame('count', $usages[0]->operation);
    }

    public function test_unknown_model_table_does_not_create_definite_usage(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

User::where('legacy_status', 'active')->get();
PHP;

        $models = new ModelIndex([
            new ModelDescriptor(
                className: 'App\Models\User',
                table: null,
                reason: 'custom_get_table',
                file: 'app/Models/User.php',
                line: 7,
            ),
        ]);

        $usages = (new EloquentUsageAnalyzer())
            ->analyze(
                source: $source,
                file: 'app/Services/UserService.php',
                models: $models,
            )
            ->usages();

        $this->assertSame([], $usages);
    }

    public function test_unrelated_static_class_is_ignored(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Support\UserGateway;

UserGateway::where('legacy_status', 'active')->get();
PHP;

        $this->assertSame(
            [],
            $this->analyze($source),
        );
    }

    /**
     * @return list<object>
     */
    private function analyze(string $source): array
    {
        $models = new ModelIndex([
            new ModelDescriptor(
                className: 'App\Models\User',
                table: 'users',
                reason: null,
                file: 'app/Models/User.php',
                line: 7,
            ),
        ]);

        return (new EloquentUsageAnalyzer())
            ->analyze(
                source: $source,
                file: 'app/Services/UserService.php',
                models: $models,
            )
            ->usages();
    }
}
