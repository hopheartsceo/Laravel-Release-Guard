<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Integration;

use Hopheartsceo\ReleaseGuard\Analysis\Application\DatabaseUsageAnalyzer;
use Hopheartsceo\ReleaseGuard\Analysis\Migrations\MigrationAnalyzer;
use Hopheartsceo\ReleaseGuard\Analysis\Release\ReleaseGuardAnalysisPipeline;
use Hopheartsceo\ReleaseGuard\Compatibility\CompatibilityEngine;
use Hopheartsceo\ReleaseGuard\Compatibility\Rules\DroppedColumnStillReferencedRule;
use Hopheartsceo\ReleaseGuard\Compatibility\Rules\RequiredColumnBreaksBaseWritesRule;
use Hopheartsceo\ReleaseGuard\Infrastructure\Git\GitRepositoryService;
use Hopheartsceo\ReleaseGuard\Source\BaseRevisionSourceProvider;
use Hopheartsceo\ReleaseGuard\Source\CandidateSourceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class EloquentReleaseGuardAnalysisPipelineTest extends TestCase
{
    private string $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = sys_get_temp_dir()
            .'/release-guard-eloquent-'
            .bin2hex(random_bytes(8));

        mkdir(
            $this->repository.'/app/Models',
            0777,
            true,
        );

        mkdir(
            $this->repository.'/app/Services',
            0777,
            true,
        );

        mkdir(
            $this->repository.'/database/migrations',
            0777,
            true,
        );

        $this->git('init', '-q');

        file_put_contents(
            $this->repository.'/app/Models/User.php',
            <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class User extends Model
{
}
PHP,
        );

        file_put_contents(
            $this->repository
                .'/app/Services/UserLookup.php',
            <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

User::where('legacy_status', 'active')->get();
PHP,
        );

        $this->git('add', '.');
        $this->commit('Base release');

        file_put_contents(
            $this->repository
                .'/database/migrations/'
                .'2026_08_17_000001_drop_legacy_status.php',
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
            $table->dropColumn('legacy_status');
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
        $this->removeDirectory(
            $this->repository,
        );

        parent::tearDown();
    }

    public function test_eloquent_base_usage_blocks_dropped_column(): void
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
                ]),
        );

        $result = $pipeline->analyzeAgainst(
            'HEAD~1',
        );

        $this->assertCount(
            1,
            $result->findings,
        );

        $finding = $result->findings[0];

        $this->assertSame(
            'DB001',
            $finding->code,
        );

        $this->assertSame(
            'users',
            $finding->table,
        );

        $this->assertSame(
            'legacy_status',
            $finding->column,
        );

        $this->assertSame(
            'app/Services/UserLookup.php',
            $finding->usage?->file,
        );
    }

    public function test_fresh_save_only_warning_is_not_a_definite_blocker(): void
    {
        $this->initializeFreshSaveRepository();

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
                    new RequiredColumnBreaksBaseWritesRule(),
                ]),
        );

        $result = $pipeline->analyzeAgainst(
            'HEAD~1',
        );

        $this->assertCount(1, $result->findings);
        $this->assertSame('DB005', $result->findings[0]->code);
        $this->assertSame(
            'eloquent_fresh_save',
            $result->findings[0]->usage?->operation,
        );
        $this->assertFalse($result->hasDefiniteBlocker());
    }

    private function initializeFreshSaveRepository(): void
    {
        $this->removeDirectory($this->repository);

        mkdir(
            $this->repository.'/app/Models',
            0777,
            true,
        );

        mkdir(
            $this->repository.'/app/Services',
            0777,
            true,
        );

        mkdir(
            $this->repository.'/database/migrations',
            0777,
            true,
        );

        $this->git('init', '-q');

        file_put_contents(
            $this->repository.'/app/Models/User.php',
            <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class User extends Model
{
}
PHP,
        );

        file_put_contents(
            $this->repository.'/app/Services/UserCreator.php',
            <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;

$user = new User([
    'name' => $name,
]);
$user->email = $email;
$user->save();
PHP,
        );

        $this->git('add', '.');
        $this->commit('Base release');

        file_put_contents(
            $this->repository
                .'/database/migrations/'
                .'2026_08_17_000001_add_country_code.php',
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

    private function commit(string $message): void
    {
        $process = new Process([
            'git',
            '-c',
            'user.name=Release Guard Tests',
            '-c',
            'user.email=release-guard@example.test',
            'commit',
            '-q',
            '-m',
            $message,
        ], $this->repository);

        $process->mustRun();
    }

    private function git(string ...$arguments): void
    {
        $process = new Process(
            ['git', ...$arguments],
            $this->repository,
        );

        $process->mustRun();
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
