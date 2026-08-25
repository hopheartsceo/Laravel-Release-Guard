<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Integration;

use Hopheartsceo\ReleaseGuard\Analysis\Application\DatabaseUsageAnalyzer;
use Hopheartsceo\ReleaseGuard\Analysis\Migrations\MigrationAnalyzer;
use Hopheartsceo\ReleaseGuard\Analysis\Release\ReleaseGuardAnalysisPipeline;
use Hopheartsceo\ReleaseGuard\Compatibility\CompatibilityEngine;
use Hopheartsceo\ReleaseGuard\Compatibility\Rules\DroppedColumnStillReferencedRule;
use Hopheartsceo\ReleaseGuard\Compatibility\Rules\DroppedTableStillReferencedRule;
use Hopheartsceo\ReleaseGuard\Compatibility\Rules\RequiredColumnBreaksBaseWritesRule;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use Hopheartsceo\ReleaseGuard\Infrastructure\Git\GitRepositoryService;
use Hopheartsceo\ReleaseGuard\Source\BaseRevisionSourceProvider;
use Hopheartsceo\ReleaseGuard\Source\CandidateSourceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class EloquentCompatibilityScenariosTest extends TestCase
{
    private string $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = sys_get_temp_dir()
            .'/release-guard-eloquent-scenarios-'
            .bin2hex(random_bytes(8));

        foreach ([
            'app/Models',
            'app/Services',
            'database/migrations',
        ] as $directory) {
            mkdir(
                $this->repository.'/'.$directory,
                0777,
                true,
            );
        }

        $this->git('init', '-q');

        $this->write(
            'app/Models/LegacyUser.php',
            <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class LegacyUser extends Model
{
    protected $table = 'legacy_users';
}
PHP,
        );

        $this->write(
            'app/Services/LegacyUserLookup.php',
            <<<'PHP'
<?php

namespace App\Services;

use App\Models\LegacyUser;

LegacyUser::where('legacy_status', 'active')->get();
PHP,
        );

        $this->write(
            'app/Models/BaseRecord.php',
            <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class BaseRecord extends Model
{
    protected $table = 'legacy_records';
}
PHP,
        );

        $this->write(
            'app/Models/Invoice.php',
            <<<'PHP'
<?php

namespace App\Models;

final class Invoice extends BaseRecord
{
}
PHP,
        );

        $this->write(
            'app/Services/InvoiceLookup.php',
            <<<'PHP'
<?php

namespace App\Services;

use App\Models\Invoice;

Invoice::first();
PHP,
        );

        $this->write(
            'app/Models/User.php',
            <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class User extends Model
{
}
PHP,
        );

        $this->write(
            'app/Services/UserWriter.php',
            <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

User::insert([
    'name' => $name,
    'email' => $email,
]);
PHP,
        );

        $this->git('add', '.');
        $this->commit('Base release');

        $this->write(
            'database/migrations/2026_08_17_000001_drop_legacy_status.php',
            <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_users', function (Blueprint $table): void {
            $table->dropColumn('legacy_status');
        });
    }
};
PHP,
        );

        $this->write(
            'database/migrations/2026_08_17_000002_drop_legacy_records.php',
            <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::drop('legacy_records');
    }
};
PHP,
        );

        $this->write(
            'database/migrations/2026_08_17_000003_add_tenant_id.php',
            <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id');
        });
    }
};
PHP,
        );

        $this->git('add', '.');
        $this->commit('Candidate release');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->repository);

        parent::tearDown();
    }

    public function test_real_eloquent_patterns_feed_compatibility_rules(): void
    {
        $result = $this->analyzeAgainstPreviousCommit();

        $actual = array_map(
            static fn ($finding): array => [
                $finding->code,
                $finding->table,
                $finding->column,
                $finding->usage?->file,
            ],
            $result->findings,
        );

        $this->assertCount(3, $actual);

        $this->assertContains(
            [
                'DB001',
                'legacy_users',
                'legacy_status',
                'app/Services/LegacyUserLookup.php',
            ],
            $actual,
        );

        $this->assertContains(
            [
                'DB003',
                'legacy_records',
                null,
                'app/Services/InvoiceLookup.php',
            ],
            $actual,
        );

        $this->assertContains(
            [
                'DB005',
                'users',
                'tenant_id',
                'app/Services/UserWriter.php',
            ],
            $actual,
        );
    }

    public function test_fresh_known_model_save_reaches_db005_as_warning_unknown(): void
    {
        $this->initializeFreshRepository(
            serviceSource: <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

$user = new User([
    'name' => $name,
]);
$user->email = $email;
$user['timezone'] = 'UTC';
$user->save();
PHP,
        );

        $result = $this->analyzeAgainstPreviousCommit();

        $this->assertCount(1, $result->findings);

        $finding = $result->findings[0];

        $this->assertSame('DB005', $finding->code);
        $this->assertSame(Severity::WARNING, $finding->severity);
        $this->assertSame(Confidence::UNKNOWN, $finding->confidence);
        $this->assertSame('users', $finding->table);
        $this->assertSame('country_code', $finding->column);
        $this->assertSame('eloquent_fresh_save', $finding->usage?->operation);
        $this->assertNull($finding->usage?->columns);
        $this->assertSame(
            'eloquent_fresh_save_semantics',
            $finding->usage?->reason,
        );
    }

    public function test_loaded_model_save_does_not_emit_fresh_save_db005(): void
    {
        $this->initializeFreshRepository(
            serviceSource: <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

$user = User::findOrFail($id);
$user->email = $email;
$user->save();
PHP,
        );

        $result = $this->analyzeAgainstPreviousCommit();

        $this->assertSame([], $this->freshSaveDb005Findings($result->findings));
    }

    public function test_ambiguous_model_save_does_not_emit_fresh_save_db005(): void
    {
        $this->initializeFreshRepository(
            serviceSource: <<<'PHP'
<?php

namespace App\Services;

$user = makeUser();
$user->save();
PHP,
        );

        $result = $this->analyzeAgainstPreviousCommit();

        $this->assertSame([], $this->freshSaveDb005Findings($result->findings));
    }

    #[DataProvider('eloquentCreateOperations')]
    public function test_eloquent_create_family_remains_warning_unknown(
        string $operation,
    ): void {
        $this->initializeFreshRepository(
            serviceSource: <<<PHP
<?php

namespace App\Services;

use App\\Models\\User;

User::{$operation}([
    'name' => \$name,
    'email' => \$email,
]);
PHP,
        );

        $result = $this->analyzeAgainstPreviousCommit();

        $this->assertCount(1, $result->findings);

        $finding = $result->findings[0];

        $this->assertSame('DB005', $finding->code);
        $this->assertSame(Severity::WARNING, $finding->severity);
        $this->assertSame(Confidence::UNKNOWN, $finding->confidence);
        $this->assertSame('users', $finding->table);
        $this->assertSame('country_code', $finding->column);
        $this->assertSame($operation, $finding->usage?->operation);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function eloquentCreateOperations(): array
    {
        return [
            'create' => ['create'],
            'forceCreate' => ['forceCreate'],
            'createQuietly' => ['createQuietly'],
            'forceCreateQuietly' => ['forceCreateQuietly'],
        ];
    }

    private function analyzeAgainstPreviousCommit(): object
    {
        $git = new GitRepositoryService(
            $this->repository,
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
                    new DroppedTableStillReferencedRule(),
                    new RequiredColumnBreaksBaseWritesRule(),
                ]),
        );

        return $pipeline->analyzeAgainst('HEAD~1');
    }

    private function initializeFreshRepository(
        string $serviceSource,
    ): void {
        $this->removeDirectory($this->repository);

        foreach ([
            'app/Models',
            'app/Services',
            'database/migrations',
        ] as $directory) {
            mkdir(
                $this->repository.'/'.$directory,
                0777,
                true,
            );
        }

        $this->git('init', '-q');

        $this->write(
            'app/Models/User.php',
            <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class User extends Model
{
}
PHP,
        );

        $this->write('app/Services/UserWriter.php', $serviceSource);

        $this->git('add', '.');
        $this->commit('Base release');

        $this->write(
            'database/migrations/2026_08_17_000001_add_country_code.php',
            <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('country_code');
        });
    }
};
PHP,
        );

        $this->git('add', '.');
        $this->commit('Candidate release');
    }

    /**
     * @param list<object> $findings
     * @return list<object>
     */
    private function freshSaveDb005Findings(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            static fn (object $finding): bool =>
                $finding->code === 'DB005'
                && $finding->usage?->operation === 'eloquent_fresh_save',
        ));
    }

    private function write(
        string $path,
        string $contents,
    ): void {
        file_put_contents(
            $this->repository.'/'.$path,
            $contents,
        );
    }

    private function commit(string $message): void
    {
        (new Process([
            'git',
            '-c',
            'user.name=Release Guard Tests',
            '-c',
            'user.email=release-guard@example.test',
            'commit',
            '-q',
            '-m',
            $message,
        ], $this->repository))->mustRun();
    }

    private function git(string ...$arguments): void
    {
        (new Process(
            ['git', ...$arguments],
            $this->repository,
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
            if ($item === '.' || $item === '..') {
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
