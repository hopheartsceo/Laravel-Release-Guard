<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Domain\Schema;

final class RenamedColumn extends SchemaChange
{
    public function __construct(
        string $table,
        public readonly string $from,
        public readonly string $to,
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
