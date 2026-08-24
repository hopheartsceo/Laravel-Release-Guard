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

    public function test_group_by_tracks_all_literal_columns(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

User::query()
    ->groupBy('account_id', 'status')
    ->get();
PHP;

        $usages = $this->analyze($source);

        $this->assertCount(2, $usages);
        $this->assertSame(
            'account_id',
            $usages[0]->column,
        );
        $this->assertSame(
            'status',
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

    public function test_literal_create_remains_conservative_about_final_columns(): void
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

    public function test_dynamic_create_payload_remains_conservative(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

User::create($request->validated());
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

    public function test_direct_static_update_is_not_treated_as_query_usage(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

User::update([
    'status' => 'active',
]);
PHP;

        $this->assertSame(
            [],
            $this->analyze($source),
        );
    }

    public function test_direct_static_delete_is_not_treated_as_query_usage(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

User::delete();
PHP;

        $this->assertSame(
            [],
            $this->analyze($source),
        );
    }

    public function test_query_builder_update_is_still_detected(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

User::query()->update([
    'status' => 'active',
]);
PHP;

        $usages = $this->analyze($source);

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(
            WriteUsage::class,
            $usages[0],
        );
        $this->assertSame('users', $usages[0]->table);
        $this->assertSame('update', $usages[0]->operation);
        $this->assertSame(
            ['status'],
            $usages[0]->columns,
        );
    }

    public function test_query_builder_delete_is_still_detected(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

User::query()->delete();
PHP;

        $usages = $this->analyze($source);

        $this->assertCount(1, $usages);
        $this->assertInstanceOf(
            TableUsage::class,
            $usages[0],
        );
        $this->assertSame('users', $usages[0]->table);
        $this->assertSame('delete', $usages[0]->operation);
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

    public function test_fresh_known_model_construction_then_direct_save_emits_fresh_save_usage(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

$user = new User();
$user->save();
PHP;

        $freshSaveUsages = $this->freshSaveUsages($this->analyze($source));

        $this->assertCount(1, $freshSaveUsages);
        $this->assertFreshSaveUsage($freshSaveUsages[0]);
    }

    public function test_literal_constructor_attributes_then_save_emit_unknown_column_fresh_save_usage(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

$user = new User([
    'name' => $name,
    'email' => $email,
]);

$user->save();
PHP;

        $freshSaveUsages = $this->freshSaveUsages($this->analyze($source));

        $this->assertCount(1, $freshSaveUsages);
        $this->assertFreshSaveUsage($freshSaveUsages[0]);
        $this->assertNull($freshSaveUsages[0]->columns);
        $this->assertSame(
            'eloquent_fresh_save_semantics',
            $freshSaveUsages[0]->reason,
        );
    }

    public function test_empty_constructor_property_assignment_then_save_emits_fresh_save_usage(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

$user = new User();
$user->email = $email;
$user->save();
PHP;

        $freshSaveUsages = $this->freshSaveUsages($this->analyze($source));

        $this->assertCount(1, $freshSaveUsages);
        $this->assertFreshSaveUsage($freshSaveUsages[0]);
    }

    public function test_empty_constructor_attribute_array_assignment_then_save_emits_fresh_save_usage(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

$user = new User();
$user['email'] = $email;
$user->save();
PHP;

        $freshSaveUsages = $this->freshSaveUsages($this->analyze($source));

        $this->assertCount(1, $freshSaveUsages);
        $this->assertFreshSaveUsage($freshSaveUsages[0]);
    }

    public function test_constructor_payload_plus_supported_assignments_then_save_emits_exact_fresh_save_usage(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

$user = new User([
    'name' => $name,
]);

$user->email = $email;
$user['timezone'] = 'UTC';
$user->save();
PHP;

        $freshSaveUsages = $this->freshSaveUsages($this->analyze($source));

        $this->assertCount(1, $freshSaveUsages);
        $this->assertFreshSaveUsage($freshSaveUsages[0]);
    }

    public function test_fresh_construction_then_unsupported_reassignment_has_no_fresh_save_usage(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

$user = new User();
$user = $this->resolveUser();
$user->save();
PHP;

        $this->assertNoFreshSaveUsages($this->analyze($source));
    }

    public function test_loaded_retrieval_shapes_then_assignment_have_no_fresh_save_usage(): void
    {
        $directRetrieval = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

$user = User::findOrFail($id);
$user->email = $email;
$user->save();
PHP;

        $queryRootRetrieval = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

$user = User::query()
    ->where('email', $email)
    ->first();

$user['name'] = $name;
$user->save();
PHP;

        $this->assertNoFreshSaveUsages($this->analyze($directRetrieval));
        $this->assertNoFreshSaveUsages($this->analyze($queryRootRetrieval));
    }

    public function test_ambiguous_helper_and_dynamic_origins_have_no_fresh_save_usage(): void
    {
        $helperOrigin = <<<'PHP'
<?php

namespace App\Services;

$user = makeUser();
$user->save();
PHP;

        $dynamicOrigin = <<<'PHP'
<?php

namespace App\Services;

$class = $this->modelClass();
$user = new $class();
$user->save();
PHP;

        $this->assertNoFreshSaveUsages($this->analyze($helperOrigin));
        $this->assertNoFreshSaveUsages($this->analyze($dynamicOrigin));
    }

    public function test_save_before_later_fresh_construction_has_no_future_derived_fresh_save_usage(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

$user->save();

$user = new User();
PHP;

        $this->assertNoFreshSaveUsages($this->analyze($source));
    }

    public function test_fresh_construction_inside_conditional_does_not_leak_to_later_save(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

if ($shouldCreate) {
    $user = new User();
}

$user->save();
PHP;

        $this->assertNoFreshSaveUsages($this->analyze($source));
    }

    public function test_valid_ordered_fresh_save_usage_carries_exact_source_metadata(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

final class RegistersUser
{
    public function handle(string $email): void
    {
        $user = new User([
            'email' => $email,
        ]);
        $user->name = 'Taylor';
        $user['timezone'] = 'UTC';
        $user->save();
    }
}
PHP;

        $freshSaveUsages = $this->freshSaveUsages($this->analyze($source));

        $this->assertCount(1, $freshSaveUsages);
        $this->assertFreshSaveUsage($freshSaveUsages[0], line: 18);
        $this->assertSame('app/Services/UserService.php', $freshSaveUsages[0]->file);
    }

    public function test_explicitly_excluded_instance_save_shapes_have_no_fresh_save_usage(): void
    {
        $sources = [
            'arbitrary receiver alias' => <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

$user = new User();
$alias = $user;
$alias->save();
PHP,
            'helper-created model' => <<<'PHP'
<?php

namespace App\Services;

$user = createUserModel();
$user->save();
PHP,
            'factory' => <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

$user = User::factory()->make();
$user->save();
PHP,
            'relationship-derived model' => <<<'PHP'
<?php

namespace App\Services;

$user = $account->users()->make();
$user->save();
PHP,
            'dynamic model class' => <<<'PHP'
<?php

namespace App\Services;

$modelClass = $this->modelClass;
$user = new $modelClass();
$user->save();
PHP,
            'dependency-injected receiver' => <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

function persist(User $user): void
{
    $user->save();
}
PHP,
            'container-resolved receiver' => <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

$user = app(User::class);
$user->save();
PHP,
            'saveOrFail' => <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

$user = new User();
$user->saveOrFail();
PHP,
            'push' => <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

$user = new User();
$user->push();
PHP,
        ];

        foreach ($sources as $scenario => $source) {
            $this->assertNoFreshSaveUsages(
                $this->analyze($source),
                $scenario,
            );
        }
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

    /**
     * @param list<object> $usages
     * @return list<WriteUsage>
     */
    private function freshSaveUsages(array $usages): array
    {
        return array_values(
            array_filter(
                $usages,
                static fn (object $usage): bool => $usage instanceof WriteUsage
                    && $usage->operation === 'eloquent_fresh_save',
            ),
        );
    }

    private function assertFreshSaveUsage(
        WriteUsage $usage,
        int $line = 8,
    ): void {
        $this->assertSame('users', $usage->table);
        $this->assertSame('eloquent_fresh_save', $usage->operation);
        $this->assertNull($usage->columns);
        $this->assertSame(
            'eloquent_fresh_save_semantics',
            $usage->reason,
        );
        $this->assertSame('app/Services/UserService.php', $usage->file);
        $this->assertSame($line, $usage->line);
    }

    /**
     * @param list<object> $usages
     */
    private function assertNoFreshSaveUsages(
        array $usages,
        string $message = '',
    ): void {
        $this->assertSame(
            [],
            $this->freshSaveUsages($usages),
            $message,
        );
    }
}
