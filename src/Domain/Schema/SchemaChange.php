<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Domain\Schema;

abstract class SchemaChange
{
    public function __construct(
        public readonly string $table,
        public readonly string $file,
        public readonly int $line,
    ) {
    }
}
