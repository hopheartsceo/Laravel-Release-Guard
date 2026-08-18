<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Infrastructure\Git;

use Hopheartsceo\ReleaseGuard\Infrastructure\Git\GitRepositoryException;
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

    public function test_failed_git_operation_throws_concise_repository_exception(): void
    {
        $service = new GitRepositoryService(
            $this->repository,
        );

        $this->expectException(
            GitRepositoryException::class,
        );
        $this->expectExceptionMessage(
            'Git operation [rev-parse] failed.',
        );

        $service->resolveRevision(
            'revision-that-does-not-exist',
        );
    }

    public function test_revision_starting_with_dash_is_not_treated_as_git_option(): void
    {
        $this->git(
            'update-ref',
            'refs/heads/-base',
            'HEAD~1',
        );

        $service = new GitRepositoryService(
            $this->repository,
        );

        $resolved = $service->resolveRevision('-base');

        $this->assertSame(
            $this->gitOutput(
                'rev-parse',
                'refs/heads/-base',
            ),
            $resolved,
        );
    }

    public function test_file_can_be_read_from_base_revision_without_checkout(): void
    {
        $service = new GitRepositoryService($this->repository);

        $candidateHead = $this->gitOutput('rev-parse', 'HEAD');
        $baseRevision = $service->resolveRevision('HEAD~1');

        $contents = $service->showFile(
            revision: $baseRevision,
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

    public function test_show_file_rejects_unverified_tree_object_id(): void
    {
        $treeObject = $this->gitOutput(
            'rev-parse',
            'HEAD^{tree}',
        );

        $service = new GitRepositoryService(
            $this->repository,
        );

        $this->expectException(
            GitRepositoryException::class,
        );
        $this->expectExceptionMessage(
            'Git operation [rev-parse] failed.',
        );

        $service->showFile(
            revision: $treeObject,
            path: 'app/OrderService.php',
        );
    }

    public function test_file_can_be_read_from_dash_prefixed_revision_safely(): void
    {
        $this->git(
            'update-ref',
            'refs/heads/-base',
            'HEAD~1',
        );

        $service = new GitRepositoryService(
            $this->repository,
        );

        $contents = $service->showFile(
            revision: '-base',
            path: 'app/OrderService.php',
        );

        $this->assertSame(
            "<?php\n\nreturn 'base';\n",
            $contents,
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

    public function test_files_can_be_listed_from_dash_prefixed_revision(): void
    {
        $this->git(
            'update-ref',
            'refs/heads/-base',
            'HEAD~1',
        );

        $service = new GitRepositoryService(
            $this->repository,
        );

        $this->assertSame(
            ['app/OrderService.php'],
            $service->listFiles('-base'),
        );
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

    public function test_changed_files_accept_dash_prefixed_base_revision(): void
    {
        $this->git(
            'update-ref',
            'refs/heads/-base',
            'HEAD~1',
        );

        $service = new GitRepositoryService(
            $this->repository,
        );

        $this->assertSame(
            ['app/OrderService.php'],
            $service->changedFiles(
                baseRevision: '-base',
                targetRevision: 'HEAD',
            ),
        );
    }

    public function test_changed_working_tree_files_accept_dash_prefixed_base_revision(): void
    {
        $this->git(
            'update-ref',
            'refs/heads/-base',
            'HEAD~1',
        );

        $service = new GitRepositoryService(
            $this->repository,
        );

        $this->assertSame(
            ['app/OrderService.php'],
            $service->changedWorkingTreeFiles(
                '-base',
            ),
        );
    }

    public function test_working_tree_file_can_be_read_safely(): void
    {
        $service = new GitRepositoryService(
            $this->repository,
        );

        $this->assertTrue(
            $service->workingTreeFileExists(
                'app/OrderService.php',
            ),
        );

        $this->assertSame(
            "<?php\n\nreturn 'candidate';\n",
            $service->readWorkingTreeFile(
                'app/OrderService.php',
            ),
        );
    }

    public function test_working_tree_path_traversal_is_rejected(): void
    {
        $service = new GitRepositoryService(
            $this->repository,
        );

        $this->expectException(
            GitRepositoryException::class,
        );
        $this->expectExceptionMessage(
            'Unsafe working tree path [../outside.php].',
        );

        $service->workingTreeFileExists(
            '../outside.php',
        );
    }

    public function test_absolute_working_tree_path_is_rejected(): void
    {
        $service = new GitRepositoryService(
            $this->repository,
        );

        $absolutePath = $this->repository
            .'/app/OrderService.php';

        $this->expectException(
            GitRepositoryException::class,
        );
        $this->expectExceptionMessage(
            'Unsafe working tree path',
        );

        $service->readWorkingTreeFile(
            $absolutePath,
        );
    }

    public function test_working_tree_symlink_cannot_escape_repository(): void
    {
        $outsideFile = $this->repository
            .'-outside.php';

        file_put_contents(
            $outsideFile,
            "<?php\n\nreturn 'outside';\n",
        );

        $link = $this->repository
            .'/app/External.php';

        try {
            $this->assertTrue(
                symlink(
                    $outsideFile,
                    $link,
                ),
            );

            $service = new GitRepositoryService(
                $this->repository,
            );

            $this->expectException(
                GitRepositoryException::class,
            );
            $this->expectExceptionMessage(
                'resolves outside the repository',
            );

            $service->readWorkingTreeFile(
                'app/External.php',
            );
        } finally {
            if (is_file($outsideFile)) {
                unlink($outsideFile);
            }
        }
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
