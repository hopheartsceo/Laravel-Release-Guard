<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Source;

use Hopheartsceo\ReleaseGuard\Infrastructure\Git\GitRepositoryService;
use Hopheartsceo\ReleaseGuard\Source\BaseRevisionSourceProvider;
use Hopheartsceo\ReleaseGuard\Source\CandidateSourceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SourceProvidersTest extends TestCase
{
    private string $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = sys_get_temp_dir()
            .'/release-guard-source-'
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
            $this->repository.'/app/LegacyReader.php',
            "<?php\n\nreturn 'base';\n",
        );

        file_put_contents(
            $this->repository.'/app/readme.txt',
            "ignore me\n",
        );

        file_put_contents(
            $this->repository
            .'/database/migrations/2026_01_01_000000_old.php',
            "<?php\n\n// old migration\n",
        );

        $this->git('add', '.');
        $this->commit('Base release');

        file_put_contents(
            $this->repository.'/app/LegacyReader.php',
            "<?php\n\nreturn 'candidate';\n",
        );

        file_put_contents(
            $this->repository
            .'/database/migrations/2026_08_16_000001_drop_status.php',
            "<?php\n\n// candidate migration\n",
        );

        $this->git('add', '.');
        $this->commit('Candidate release');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->repository);

        parent::tearDown();
    }

    public function test_base_revision_provider_reads_php_application_files_from_base(): void
    {
        $provider = new BaseRevisionSourceProvider(
            new GitRepositoryService($this->repository),
        );

        $files = $provider->applicationFiles('HEAD~1');

        $this->assertCount(1, $files);
        $this->assertSame(
            'app/LegacyReader.php',
            $files[0]->path,
        );
        $this->assertSame(
            "<?php\n\nreturn 'base';\n",
            $files[0]->contents,
        );
    }

    public function test_candidate_provider_returns_changed_migrations_only(): void
    {
        $provider = new CandidateSourceProvider(
            new GitRepositoryService($this->repository),
        );

        $files = $provider->migrationFilesAgainst('HEAD~1');

        $this->assertCount(1, $files);
        $this->assertSame(
            'database/migrations/2026_08_16_000001_drop_status.php',
            $files[0]->path,
        );
        $this->assertSame(
            "<?php\n\n// candidate migration\n",
            $files[0]->contents,
        );
    }

    public function test_candidate_provider_includes_untracked_migrations(): void
    {
        $path = 'database/migrations/'
            .'2026_08_16_000002_drop_legacy_orders.php';

        file_put_contents(
            $this->repository.'/'.$path,
            "<?php\n\n// untracked migration\n",
        );

        $provider = new CandidateSourceProvider(
            new GitRepositoryService($this->repository),
        );

        $files = $provider->migrationFilesAgainst('HEAD~1');

        $paths = array_map(
            static fn ($file): string => $file->path,
            $files,
        );

        $this->assertContains(
            'database/migrations/2026_08_16_000001_drop_status.php',
            $paths,
        );

        $this->assertContains($path, $paths);
    }

    public function test_deleted_candidate_migration_is_not_read(): void
    {
        unlink(
            $this->repository
            .'/database/migrations/2026_08_16_000001_drop_status.php',
        );

        $provider = new CandidateSourceProvider(
            new GitRepositoryService($this->repository),
        );

        $files = $provider->migrationFilesAgainst('HEAD~1');

        $this->assertSame([], $files);
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
