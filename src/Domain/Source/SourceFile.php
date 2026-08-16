<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Domain\Source;

final class SourceFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $contents,
    ) {
    }
}
