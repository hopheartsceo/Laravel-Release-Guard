<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Infrastructure\Git;

use Hopheartsceo\ReleaseGuard\Infrastructure\Git\GitRepositoryService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GitRepositoryServiceTest extends TestCase
{
    private string $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = sys_get_temp_dir()
            .'/release-guard-git-'
            .bin2hex(random_bytes(8));

        mkdir($this->repository, 0777, true);

        $this->git('init', '-q');

        mkdir($this->repository.'/app', 0777, true);

        file_put_contents(
            $this->repository.'/app/OrderService.php',
            "<?php\n\nreturn 'base';\n",
        );

        $this->git('add', '.');
        $this->commit('Create base release');

        file_put_contents(
            $this->repository.'/app/OrderService.php',
            "<?php\n\nreturn 'candidate';\n",
        );

        $this->git('add', '.');
        $this->commit('Create candidate release');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->repository);

        parent::tearDown();
    }

    public function test_revision_is_resolved_to_full_commit_sha(): void
    {
        $service = new GitRepositoryService($this->repository);

        $resolved = $service->resolveRevision('HEAD~1');

        $this->assertSame(
            $this->gitOutput('rev-parse', 'HEAD~1'),
            $resolved,
        );

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{40}$/',
            $resolved,
        );
    }

    public function test_file_can_be_read_from_base_revision_without_checkout(): void
    {
        $service = new GitRepositoryService($this->repository);

        $candidateHead = $this->gitOutput('rev-parse', 'HEAD');

        $contents = $service->showFile(
            revision: 'HEAD~1',
            path: 'app/OrderService.php',
        );

        $this->assertSame(
            "<?php\n\nreturn 'base';\n",
            $contents,
        );

        $this->assertSame(
            $candidateHead,
            $this->gitOutput('rev-parse', 'HEAD'),
        );

        $this->assertSame(
            "<?php\n\nreturn 'candidate';\n",
            file_get_contents(
                $this->repository.'/app/OrderService.php',
            ),
        );
    }

    public function test_files_can_be_listed_from_specific_revision(): void
    {
        $service = new GitRepositoryService($this->repository);

        $files = $service->listFiles('HEAD~1');

        $this->assertSame([
            'app/OrderService.php',
        ], $files);
    }

    public function test_changed_files_can_be_listed_between_revisions(): void
    {
        $service = new GitRepositoryService($this->repository);

        $files = $service->changedFiles(
            baseRevision: 'HEAD~1',
            targetRevision: 'HEAD',
        );

        $this->assertSame([
            'app/OrderService.php',
        ], $files);
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
