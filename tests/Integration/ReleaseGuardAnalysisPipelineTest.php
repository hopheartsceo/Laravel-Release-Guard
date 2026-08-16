<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Integration;

use Hopheartsceo\ReleaseGuard\Analysis\Application\DatabaseUsageAnalyzer;
use Hopheartsceo\ReleaseGuard\Analysis\Migrations\MigrationAnalyzer;
use Hopheartsceo\ReleaseGuard\Analysis\Release\ReleaseGuardAnalysisPipeline;
use Hopheartsceo\ReleaseGuard\Compatibility\CompatibilityEngine;
use Hopheartsceo\ReleaseGuard\Compatibility\Rules\DroppedColumnStillReferencedRule;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Infrastructure\Git\GitRepositoryService;
use Hopheartsceo\ReleaseGuard\Source\BaseRevisionSourceProvider;
use Hopheartsceo\ReleaseGuard\Source\CandidateSourceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ReleaseGuardAnalysisPipelineTest extends TestCase
{
    private string $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = sys_get_temp_dir()
            .'/release-guard-pipeline-'
            .bin2hex(random_bytes(8));

        mkdir($this->repository, 0777, true);
        mkdir($this->repository.'/app', 0777, true);
        mkdir(
            $this->repository.'/database/migrations',
            0777,
            true,
        );

        $this->git('init', '-q');

        file_put_contents(
            $this->repository.'/app/UserLookup.php',
            <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')
    ->where('legacy_status', 'active')
    ->get();
PHP,
        );

        $this->git('add', '.');
        $this->commit('Base release');

        file_put_contents(
            $this->repository
            .'/database/migrations/2026_08_16_000001_drop_legacy_status.php',
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
        $this->removeDirectory($this->repository);

        parent::tearDown();
    }

    public function test_git_revision_is_analyzed_end_to_end_without_checkout(): void
    {
        $git = new GitRepositoryService($this->repository);

        $pipeline = new ReleaseGuardAnalysisPipeline(
            git: $git,
            baseSources: new BaseRevisionSourceProvider($git),
            candidateSources: new CandidateSourceProvider($git),
            databaseUsageAnalyzer: new DatabaseUsageAnalyzer(),
            migrationAnalyzer: new MigrationAnalyzer(),
            compatibilityEngine: new CompatibilityEngine([
                new DroppedColumnStillReferencedRule(),
            ]),
        );

        $headBefore = $this->gitOutput('rev-parse', 'HEAD');

        $result = $pipeline->analyzeAgainst('HEAD~1');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{40}$/',
            $result->baseRevision,
        );

        $this->assertSame(
            $this->gitOutput('rev-parse', 'HEAD~1'),
            $result->baseRevision,
        );

        $this->assertSame(1, $result->baseApplicationFileCount);
        $this->assertSame(1, $result->candidateMigrationFileCount);

        $this->assertCount(1, $result->findings);

        $finding = $result->findings[0];

        $this->assertSame('DB001', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('users', $finding->table);
        $this->assertSame('legacy_status', $finding->column);

        $this->assertSame(
            $headBefore,
            $this->gitOutput('rev-parse', 'HEAD'),
        );
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

    private function gitOutput(string ...$arguments): string
    {
        $process = new Process(
            ['git', ...$arguments],
            $this->repository,
        );

        $process->mustRun();

        return trim($process->getOutput());
    }

    private function removeDirectory(string $directory): void
    {
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

            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
