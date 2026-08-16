<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Domain\Application;

abstract class DatabaseUsage
{
    public function __construct(
        public readonly string $table,
        public readonly string $file,
        public readonly int $line,
    ) {
    }
}
