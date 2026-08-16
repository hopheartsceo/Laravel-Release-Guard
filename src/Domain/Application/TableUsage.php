<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Domain\Application;

final class TableUsage extends DatabaseUsage
{
    public function __construct(
        ?string $table,
        public readonly string $operation,
        string $file,
        int $line,
    ) {
        parent::__construct(
            table: $table,
            file: $file,
            line: $line,
        );
    }
}
