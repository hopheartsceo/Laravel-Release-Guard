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

        $result = $pipeline->analyzeAgainst('HEAD~1');

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
