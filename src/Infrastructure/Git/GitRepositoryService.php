<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Infrastructure\Git;

use Symfony\Component\Process\Process;

final class GitRepositoryService
{
    public function __construct(
        private readonly string $repository,
    ) {
    }

    public function resolveRevision(string $revision): string
    {
        return trim($this->run([
            'rev-parse',
            '--verify',
            $revision.'^{commit}',
        ]));
    }

    public function showFile(
        string $revision,
        string $path,
    ): string {
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
        return is_file($this->repository.'/'.$path);
    }

    public function readWorkingTreeFile(string $path): string
    {
        $contents = file_get_contents(
            $this->repository.'/'.$path,
        );

        if ($contents === false) {
            throw new \RuntimeException(
                sprintf(
                    'Unable to read working tree file [%s].',
                    $path,
                ),
            );
        }

        return $contents;
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

        $process->mustRun();

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
