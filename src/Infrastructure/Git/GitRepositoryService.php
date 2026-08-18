<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Infrastructure\Git;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class GitRepositoryService
{
    /**
     * Commit object IDs verified by resolveRevision().
     *
     * @var array<string, true>
     */
    private array $verifiedCommitIds = [];

    public function __construct(
        private readonly string $repository,
    ) {
    }

    public function resolveRevision(string $revision): string
    {
        $resolved = trim($this->run([
            'rev-parse',
            '--verify',
            '--end-of-options',
            $revision.'^{commit}',
        ]));

        $this->verifiedCommitIds[$resolved] = true;

        return $resolved;
    }

    public function showFile(
        string $revision,
        string $path,
    ): string {
        $revision = $this->resolvedRevisionForObjectLookup(
            $revision,
        );

        return $this->run([
            'show',
            $revision.':'.$path,
        ]);
    }

    /**
     * @return list<string>
     */
    public function listFiles(
        string $revision,
        ?string $path = null,
    ): array {
        $revision = $this->resolvedRevisionForObjectLookup(
            $revision,
        );

        $arguments = [
            'ls-tree',
            '-r',
            '-z',
            '--name-only',
            $revision,
        ];

        if ($path !== null && $path !== '') {
            $arguments[] = '--';
            $arguments[] = $path;
        }

        return $this->nullSeparated(
            $this->run($arguments),
        );
    }

    /**
     * @return list<string>
     */
    public function changedFiles(
        string $baseRevision,
        string $targetRevision = 'HEAD',
    ): array {
        $baseRevision = $this->resolvedRevisionForObjectLookup(
            $baseRevision,
        );

        $targetRevision = $this->resolvedRevisionForObjectLookup(
            $targetRevision,
        );

        return $this->nullSeparated(
            $this->run([
                'diff',
                '--name-only',
                '-z',
                $baseRevision,
                $targetRevision,
                '--',
            ]),
        );
    }

    /**
     * @return list<string>
     */
    public function changedWorkingTreeFiles(
        string $baseRevision,
    ): array {
        $baseRevision = $this->resolvedRevisionForObjectLookup(
            $baseRevision,
        );

        $tracked = $this->nullSeparated(
            $this->run([
                'diff',
                '--name-only',
                '-z',
                $baseRevision,
                '--',
            ]),
        );

        $untracked = $this->nullSeparated(
            $this->run([
                'ls-files',
                '--others',
                '--exclude-standard',
                '-z',
            ]),
        );

        $files = array_values(array_unique([
            ...$tracked,
            ...$untracked,
        ]));

        sort($files);

        return $files;
    }

    public function workingTreeFileExists(string $path): bool
    {
        return $this->resolvedWorkingTreeFile($path) !== null;
    }

    public function readWorkingTreeFile(string $path): string
    {
        $resolvedPath = $this->resolvedWorkingTreeFile(
            $path,
        );

        if ($resolvedPath === null) {
            throw new GitRepositoryException(
                sprintf(
                    'Unable to read working tree file [%s].',
                    $path,
                ),
            );
        }

        $contents = file_get_contents($resolvedPath);

        if ($contents === false) {
            throw new GitRepositoryException(
                sprintf(
                    'Unable to read working tree file [%s].',
                    $path,
                ),
            );
        }

        return $contents;
    }

    private function resolvedWorkingTreeFile(
        string $path,
    ): ?string {
        $candidate = $this->workingTreePath($path);

        if (! is_file($candidate)) {
            return null;
        }

        $repository = realpath($this->repository);
        $resolved = realpath($candidate);

        if (
            $repository === false
            || $resolved === false
        ) {
            throw new GitRepositoryException(
                sprintf(
                    'Unable to resolve working tree file [%s].',
                    $path,
                ),
            );
        }

        if (! $this->isPathWithinRepository(
            $resolved,
            $repository,
        )) {
            throw new GitRepositoryException(
                sprintf(
                    'Working tree path [%s] resolves outside the repository.',
                    $path,
                ),
            );
        }

        return $resolved;
    }

    private function workingTreePath(string $path): string
    {
        if ($this->isUnsafeWorkingTreePath($path)) {
            throw new GitRepositoryException(
                sprintf(
                    'Unsafe working tree path [%s].',
                    $path,
                ),
            );
        }

        return rtrim(
            $this->repository,
            '/\\',
        ).DIRECTORY_SEPARATOR.$path;
    }

    private function isUnsafeWorkingTreePath(
        string $path,
    ): bool {
        if (
            $path === ''
            || str_contains($path, "\0")
            || preg_match(
                '/\A(?:[\/\\\\]|[A-Za-z]:)/',
                $path,
            ) === 1
        ) {
            return true;
        }

        $segments = preg_split(
            '/[\/\\\\]+/',
            $path,
        );

        if ($segments === false) {
            return true;
        }

        return in_array('..', $segments, true)
            || in_array('.', $segments, true);
    }

    private function isPathWithinRepository(
        string $path,
        string $repository,
    ): bool {
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $repository = strtolower($repository);
        }

        return $path === $repository
            || str_starts_with(
                $path,
                rtrim(
                    $repository,
                    '/\\',
                ).DIRECTORY_SEPARATOR,
            );
    }

    private function resolvedRevisionForObjectLookup(
        string $revision,
    ): string {
        if (isset($this->verifiedCommitIds[$revision])) {
            return $revision;
        }

        return $this->resolveRevision($revision);
    }

    /**
     * @param list<string> $arguments
     */
    private function run(array $arguments): string
    {
        $process = new Process(
            ['git', ...$arguments],
            $this->repository,
        );

        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            throw new GitRepositoryException(
                sprintf(
                    'Git operation [%s] failed.',
                    $arguments[0] ?? 'unknown',
                ),
                0,
                $exception,
            );
        }

        return $process->getOutput();
    }

    /**
     * @return list<string>
     */
    private function nullSeparated(string $output): array
    {
        if ($output === '') {
            return [];
        }

        $items = explode("\0", $output);

        if (end($items) === '') {
            array_pop($items);
        }

        return array_values($items);
    }
}
