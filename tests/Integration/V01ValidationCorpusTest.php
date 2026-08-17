<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Integration;

use Hopheartsceo\ReleaseGuard\Analysis\Application\DatabaseUsageAnalyzer;
use Hopheartsceo\ReleaseGuard\Analysis\Migrations\MigrationAnalyzer;
use Hopheartsceo\ReleaseGuard\Analysis\Release\ReleaseGuardAnalysisPipeline;
use Hopheartsceo\ReleaseGuard\Compatibility\CompatibilityEngine;
use Hopheartsceo\ReleaseGuard\Compatibility\Rules\DroppedColumnStillReferencedRule;
use Hopheartsceo\ReleaseGuard\Compatibility\Rules\DroppedTableStillReferencedRule;
use Hopheartsceo\ReleaseGuard\Compatibility\Rules\RenamedColumnStillReferencedRule;
use Hopheartsceo\ReleaseGuard\Compatibility\Rules\RenamedTableStillReferencedRule;
use Hopheartsceo\ReleaseGuard\Compatibility\Rules\RequiredColumnBreaksBaseWritesRule;
use Hopheartsceo\ReleaseGuard\Compatibility\Rules\UnanalyzableMigrationOperationRule;
use Hopheartsceo\ReleaseGuard\Domain\AnalysisResult;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Infrastructure\Git\GitRepositoryService;
use Hopheartsceo\ReleaseGuard\Source\BaseRevisionSourceProvider;
use Hopheartsceo\ReleaseGuard\Source\CandidateSourceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class V01ValidationCorpusTest extends TestCase
{
    #[DataProvider('scenarios')]
    public function test_v01_validation_scenario(
        array $baseFiles,
        array $candidateMigrations,
        string $expectation,
        ?string $expectedCode,
    ): void {
        $result = $this->analyzeScenario(
            $baseFiles,
            $candidateMigrations,
        );

        if ($expectation === 'block') {
            $this->assertDefiniteBlocker(
                $result,
                $expectedCode,
            );

            return;
        }

        if ($expectation === 'pass') {
            $this->assertFalse(
                $result->hasDefiniteBlocker(),
                'Expected no definite blocker.',
            );

            return;
        }

        $this->assertFalse(
            $result->hasDefiniteBlocker(),
            'UNKNOWN scenarios must not become definite blockers.',
        );

        $unknownWarnings = array_values(array_filter(
            $result->findings,
            static fn ($finding): bool =>
                $finding->severity === Severity::WARNING
                && $finding->confidence === Confidence::UNKNOWN,
        ));

        $this->assertNotEmpty(
            $unknownWarnings,
            'Expected at least one WARNING / UNKNOWN finding.',
        );
    }

    public static function scenarios(): iterable
    {
        yield '01 drop users.phone while base Eloquent where uses phone' => [
            [
                'app/Models/User.php' => self::model('User'),
                'app/Services/UserLookup.php' => self::eloquent(
                    'User',
                    "User::where('phone', \$phone)->first();",
                ),
            ],
            [
                'database/migrations/000001_drop_phone.php' =>
                    self::migration(<<<'BODY'
Schema::table('users', function (Blueprint $table): void {
    $table->dropColumn('phone');
});
BODY),
            ],
            'block',
            'DB001',
        ];

        yield '02 drop users.phone while base Query Builder select uses phone' => [
            [
                'app/Services/UserReader.php' => self::queryBuilder(<<<'BODY'
DB::table('users')
    ->select('phone')
    ->first();
BODY),
            ],
            [
                'database/migrations/000002_drop_phone.php' =>
                    self::migration(<<<'BODY'
Schema::table('users', function (Blueprint $table): void {
    $table->dropColumn('phone');
});
BODY),
            ],
            'block',
            'DB001',
        ];

        yield '03 rename users.phone to mobile while base uses phone' => [
            [
                'app/Services/UserLookup.php' => self::queryBuilder(<<<'BODY'
DB::table('users')
    ->where('phone', $phone)
    ->first();
BODY),
            ],
            [
                'database/migrations/000003_rename_phone.php' =>
                    self::migration(<<<'BODY'
Schema::table('users', function (Blueprint $table): void {
    $table->renameColumn('phone', 'mobile');
});
BODY),
            ],
            'block',
            'DB002',
        ];

        yield '04 drop legacy_orders while base Query Builder references it' => [
            [
                'app/Services/LegacyOrderReader.php' =>
                    self::queryBuilder(
                        "DB::table('legacy_orders')->get();",
                    ),
            ],
            [
                'database/migrations/000004_drop_legacy_orders.php' =>
                    self::migration(
                        "Schema::drop('legacy_orders');",
                    ),
            ],
            'block',
            'DB003',
        ];

        yield '05 rename customers to clients while Eloquent model maps customers' => [
            [
                'app/Models/Customer.php' =>
                    self::model('Customer', 'customers'),
                'app/Services/CustomerReader.php' =>
                    self::eloquent(
                        'Customer',
                        'Customer::first();',
                    ),
            ],
            [
                'database/migrations/000005_rename_customers.php' =>
                    self::migration(
                        "Schema::rename('customers', 'clients');",
                    ),
            ],
            'block',
            'DB004',
        ];

        yield '06 add required users.country_code while literal User create omits it' => [
            [
                'app/Models/User.php' => self::model('User'),
                'app/Services/UserCreator.php' =>
                    self::eloquent('User', <<<'BODY'
User::create([
    'name' => $name,
    'email' => $email,
]);
BODY),
            ],
            [
                'database/migrations/000006_add_country_code.php' =>
                    self::migration(<<<'BODY'
Schema::table('users', function (Blueprint $table): void {
    $table->string('country_code');
});
BODY),
            ],
            'unknown',
            null,
        ];

        yield '07 add required orders.currency while Query Builder insert omits it' => [
            [
                'app/Services/OrderCreator.php' =>
                    self::queryBuilder(<<<'BODY'
DB::table('orders')->insert([
    'user_id' => $userId,
    'total' => $total,
]);
BODY),
            ],
            [
                'database/migrations/000007_add_currency.php' =>
                    self::migration(<<<'BODY'
Schema::table('orders', function (Blueprint $table): void {
    $table->string('currency');
});
BODY),
            ],
            'block',
            'DB005',
        ];

        yield '08 one of multiple deterministic writes omits required column' => [
            [
                'app/Services/UserCreator.php' =>
                    self::queryBuilder(<<<'BODY'
DB::table('users')->insert([
    'name' => $name,
    'email' => $email,
    'country_code' => 'PS',
]);

DB::table('users')->insert([
    'name' => $otherName,
    'email' => $otherEmail,
]);
BODY),
            ],
            [
                'database/migrations/000008_add_country_code.php' =>
                    self::migration(<<<'BODY'
Schema::table('users', function (Blueprint $table): void {
    $table->string('country_code');
});
BODY),
            ],
            'block',
            'DB005',
        ];

        yield '09 drop column used in orderBy' => [
            [
                'app/Services/UserReader.php' =>
                    self::queryBuilder(<<<'BODY'
DB::table('users')
    ->orderBy('legacy_rank')
    ->get();
BODY),
            ],
            [
                'database/migrations/000009_drop_legacy_rank.php' =>
                    self::migration(<<<'BODY'
Schema::table('users', function (Blueprint $table): void {
    $table->dropColumn('legacy_rank');
});
BODY),
            ],
            'block',
            'DB001',
        ];

        yield '10 drop column used in pluck' => [
            [
                'app/Services/UserReader.php' =>
                    self::queryBuilder(
                        "DB::table('users')->pluck('phone');",
                    ),
            ],
            [
                'database/migrations/000010_drop_phone.php' =>
                    self::migration(<<<'BODY'
Schema::table('users', function (Blueprint $table): void {
    $table->dropColumn('phone');
});
BODY),
            ],
            'block',
            'DB001',
        ];

        yield '11 drop column used in update literal payload' => [
            [
                'app/Services/UserWriter.php' =>
                    self::queryBuilder(<<<'BODY'
DB::table('users')
    ->where('id', $id)
    ->update([
        'legacy_status' => 'active',
    ]);
BODY),
            ],
            [
                'database/migrations/000011_drop_legacy_status.php' =>
                    self::migration(<<<'BODY'
Schema::table('users', function (Blueprint $table): void {
    $table->dropColumn('legacy_status');
});
BODY),
            ],
            'block',
            'DB001',
        ];

        yield '12 drop table used by explicit table mapped model' => [
            [
                'app/Models/LegacyOrder.php' =>
                    self::model(
                        'LegacyOrder',
                        'legacy_orders',
                    ),
                'app/Services/LegacyOrderReader.php' =>
                    self::eloquent(
                        'LegacyOrder',
                        'LegacyOrder::first();',
                    ),
            ],
            [
                'database/migrations/000012_drop_legacy_orders.php' =>
                    self::migration(
                        "Schema::drop('legacy_orders');",
                    ),
            ],
            'block',
            'DB003',
        ];

        yield '13 add nullable column' => [
            [
                'app/Services/UserCreator.php' =>
                    self::queryBuilder(<<<'BODY'
DB::table('users')->insert([
    'name' => $name,
    'email' => $email,
]);
BODY),
            ],
            [
                'database/migrations/000013_add_country_code.php' =>
                    self::migration(<<<'BODY'
Schema::table('users', function (Blueprint $table): void {
    $table->string('country_code')->nullable();
});
BODY),
            ],
            'pass',
            null,
        ];

        yield '14 add required column with default' => [
            [
                'app/Services/UserCreator.php' =>
                    self::queryBuilder(<<<'BODY'
DB::table('users')->insert([
    'name' => $name,
    'email' => $email,
]);
BODY),
            ],
            [
                'database/migrations/000014_add_country_code.php' =>
                    self::migration(<<<'BODY'
Schema::table('users', function (Blueprint $table): void {
    $table->string('country_code')->default('PS');
});
BODY),
            ],
            'pass',
            null,
        ];

        yield '15 all deterministic writes include required column' => [
            [
                'app/Services/UserCreator.php' =>
                    self::queryBuilder(<<<'BODY'
DB::table('users')->insert([
    'name' => $name,
    'email' => $email,
    'country_code' => 'PS',
]);

DB::table('users')->insertGetId([
    'name' => $otherName,
    'email' => $otherEmail,
    'country_code' => 'PS',
]);
BODY),
            ],
            [
                'database/migrations/000015_add_country_code.php' =>
                    self::migration(<<<'BODY'
Schema::table('users', function (Blueprint $table): void {
    $table->string('country_code');
});
BODY),
            ],
            'pass',
            null,
        ];

        yield '16 rename unused column' => [
            [
                'app/Services/UserReader.php' =>
                    self::queryBuilder(<<<'BODY'
DB::table('users')
    ->where('email', $email)
    ->first();
BODY),
            ],
            [
                'database/migrations/000016_rename_phone.php' =>
                    self::migration(<<<'BODY'
Schema::table('users', function (Blueprint $table): void {
    $table->renameColumn('phone', 'mobile');
});
BODY),
            ],
            'pass',
            null,
        ];

        yield '17 drop unused table' => [
            [
                'app/Services/OrderReader.php' =>
                    self::queryBuilder(
                        "DB::table('orders')->get();",
                    ),
            ],
            [
                'database/migrations/000017_drop_legacy_orders.php' =>
                    self::migration(
                        "Schema::drop('legacy_orders');",
                    ),
            ],
            'pass',
            null,
        ];

        yield '18 add new table' => [
            [],
            [
                'database/migrations/000018_create_audit_logs.php' =>
                    self::migration(<<<'BODY'
Schema::create('audit_logs', function (Blueprint $table): void {
    $table->string('message');
});
BODY),
            ],
            'pass',
            null,
        ];

        yield '19 add index only' => [
            [],
            [
                'database/migrations/000019_add_email_index.php' =>
                    self::migration(<<<'BODY'
Schema::table('users', function (Blueprint $table): void {
    $table->index('email');
});
BODY),
            ],
            'pass',
            null,
        ];

        yield '20 add nullable foreignId' => [
            [
                'app/Services/OrderCreator.php' =>
                    self::queryBuilder(<<<'BODY'
DB::table('orders')->insert([
    'total' => $total,
]);
BODY),
            ],
            [
                'database/migrations/000020_add_assigned_to.php' =>
                    self::migration(<<<'BODY'
Schema::table('orders', function (Blueprint $table): void {
    $table->foreignId('assigned_to')->nullable();
});
BODY),
            ],
            'pass',
            null,
        ];

        yield '21 raw DB statement migration must produce unknown warning' => [
            [],
            [
                'database/migrations/000021_raw_statement.php' =>
                    self::migration(<<<'BODY'
DB::statement(
    'ALTER TABLE users DROP COLUMN legacy_status'
);
BODY),
            ],
            'unknown',
            null,
        ];

        yield '22 dynamic table name must produce unknown warning' => [
            [],
            [
                'database/migrations/000022_dynamic_table.php' =>
                    self::migration(<<<'BODY'
$tableName = resolveTableName();

Schema::table($tableName, function (Blueprint $table): void {
    $table->dropColumn('phone');
});
BODY),
            ],
            'unknown',
            null,
        ];

        yield '23 dynamic column name must produce unknown warning' => [
            [],
            [
                'database/migrations/000023_dynamic_column.php' =>
                    self::migration(<<<'BODY'
$column = resolveColumnName();

Schema::table('users', function (Blueprint $table) use ($column): void {
    $table->dropColumn($column);
});
BODY),
            ],
            'unknown',
            null,
        ];

        yield '24 dynamic insert payload must produce unknown warning' => [
            [
                'app/Models/User.php' => self::model('User'),
                'app/Services/UserCreator.php' =>
                    self::eloquent('User', <<<'BODY'
User::create(
    $request->validated(),
);
BODY),
            ],
            [
                'database/migrations/000024_add_country_code.php' =>
                    self::migration(<<<'BODY'
Schema::table('users', function (Blueprint $table): void {
    $table->string('country_code');
});
BODY),
            ],
            'unknown',
            null,
        ];
    }

    private function assertDefiniteBlocker(
        AnalysisResult $result,
        ?string $expectedCode,
    ): void {
        $matches = array_values(array_filter(
            $result->findings,
            static fn ($finding): bool =>
                $finding->code === $expectedCode
                && $finding->severity === Severity::BLOCKER
                && $finding->confidence === Confidence::DEFINITE,
        ));

        $this->assertNotEmpty(
            $matches,
            sprintf(
                'Expected definite blocker %s.',
                $expectedCode ?? 'unknown',
            ),
        );

        $this->assertTrue(
            $result->hasDefiniteBlocker(),
        );
    }

    /**
     * @param array<string, string> $baseFiles
     * @param array<string, string> $candidateMigrations
     */
    private function analyzeScenario(
        array $baseFiles,
        array $candidateMigrations,
    ): AnalysisResult {
        $repository = sys_get_temp_dir()
            .'/release-guard-v01-corpus-'
            .bin2hex(random_bytes(8));

        mkdir($repository, 0777, true);

        try {
            $this->git($repository, 'init', '-q');

            foreach ($baseFiles as $path => $contents) {
                $this->write(
                    $repository,
                    $path,
                    $contents,
                );
            }

            $this->git($repository, 'add', '.');

            (new Process([
                'git',
                '-c',
                'user.name=Release Guard Tests',
                '-c',
                'user.email=release-guard@example.test',
                'commit',
                '--allow-empty',
                '-q',
                '-m',
                'Base release',
            ], $repository))->mustRun();

            foreach (
                $candidateMigrations
                as $path => $contents
            ) {
                $this->write(
                    $repository,
                    $path,
                    $contents,
                );
            }

            $git = new GitRepositoryService(
                $repository,
            );

            $pipeline = new ReleaseGuardAnalysisPipeline(
                git: $git,
                baseSources:
                    new BaseRevisionSourceProvider($git),
                candidateSources:
                    new CandidateSourceProvider($git),
                databaseUsageAnalyzer:
                    new DatabaseUsageAnalyzer(),
                migrationAnalyzer:
                    new MigrationAnalyzer(),
                compatibilityEngine:
                    new CompatibilityEngine([
                        new DroppedColumnStillReferencedRule(),
                        new RenamedColumnStillReferencedRule(),
                        new DroppedTableStillReferencedRule(),
                        new RenamedTableStillReferencedRule(),
                        new RequiredColumnBreaksBaseWritesRule(),
                        new UnanalyzableMigrationOperationRule(),
                    ]),
            );

            return $pipeline->analyzeAgainst(
                'HEAD',
            );
        } finally {
            $this->removeDirectory(
                $repository,
            );
        }
    }

    private static function model(
        string $class,
        ?string $table = null,
    ): string {
        $tableProperty = $table === null
            ? ''
            : "    protected \$table = '{$table}';\n";

        return <<<PHP
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class {$class} extends Model
{
{$tableProperty}}

PHP;
    }

    private static function eloquent(
        string $model,
        string $body,
    ): string {
        return <<<PHP
<?php

namespace App\Services;

use App\Models\\{$model};

{$body}

PHP;
    }

    private static function queryBuilder(
        string $body,
    ): string {
        return <<<PHP
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

{$body}

PHP;
    }

    private static function migration(
        string $body,
    ): string {
        $indented = implode(
            "\n",
            array_map(
                static fn (string $line): string =>
                    '        '.$line,
                explode("\n", trim($body)),
            ),
        );

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
{$indented}
    }
};

PHP;
    }

    private function write(
        string $repository,
        string $path,
        string $contents,
    ): void {
        $fullPath = $repository.'/'.$path;
        $directory = dirname($fullPath);

        if (! is_dir($directory)) {
            mkdir(
                $directory,
                0777,
                true,
            );
        }

        file_put_contents(
            $fullPath,
            $contents,
        );
    }

    private function git(
        string $repository,
        string ...$arguments,
    ): void {
        (new Process(
            ['git', ...$arguments],
            $repository,
        ))->mustRun();
    }

    private function removeDirectory(
        string $directory,
    ): void {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (
                $item === '.'
                || $item === '..'
            ) {
                continue;
            }

            $path = $directory.'/'.$item;

            if (
                is_dir($path)
                && ! is_link($path)
            ) {
                $this->removeDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
