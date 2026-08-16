<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Source;

use Hopheartsceo\ReleaseGuard\Domain\Source\SourceFile;
use Hopheartsceo\ReleaseGuard\Infrastructure\Git\GitRepositoryService;

final class CandidateSourceProvider
{
    public function __construct(
        private readonly GitRepositoryService $git,
    ) {
    }

    /**
     * @param list<string> $paths
     * @return list<SourceFile>
     */
    public function migrationFilesAgainst(
        string $baseRevision,
        array $paths = ['database/migrations'],
    ): array {
        $sources = [];

        foreach (
            $this->git->changedWorkingTreeFiles($baseRevision)
            as $file
        ) {
            if (
                ! str_ends_with($file, '.php')
                || ! $this->isInsideAnyPath($file, $paths)
                || ! $this->git->workingTreeFileExists($file)
            ) {
                continue;
            }

            $sources[] = new SourceFile(
                path: $file,
                contents: $this->git->readWorkingTreeFile($file),
            );
        }

        return $sources;
    }

    /**
     * @param list<string> $paths
     */
    private function isInsideAnyPath(
        string $file,
        array $paths,
    ): bool {
        foreach ($paths as $path) {
            $path = rtrim($path, '/');

            if (
                $file === $path
                || str_starts_with($file, $path.'/')
            ) {
                return true;
            }
        }

        return false;
    }
}
