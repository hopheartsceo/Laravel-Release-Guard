<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Source;

use Hopheartsceo\ReleaseGuard\Domain\Source\SourceFile;
use Hopheartsceo\ReleaseGuard\Infrastructure\Git\GitRepositoryService;

final class BaseRevisionSourceProvider
{
    public function __construct(
        private readonly GitRepositoryService $git,
    ) {
    }

    /**
     * @param list<string> $paths
     * @return list<SourceFile>
     */
    public function applicationFiles(
        string $revision,
        array $paths = ['app'],
    ): array {
        $filePaths = [];

        foreach ($paths as $path) {
            foreach ($this->git->listFiles($revision, $path) as $file) {
                if (! str_ends_with($file, '.php')) {
                    continue;
                }

                $filePaths[$file] = true;
            }
        }

        $filePaths = array_keys($filePaths);

        sort($filePaths);

        $sources = [];

        foreach ($filePaths as $file) {
            $sources[] = new SourceFile(
                path: $file,
                contents: $this->git->showFile(
                    revision: $revision,
                    path: $file,
                ),
            );
        }

        return $sources;
    }
}
