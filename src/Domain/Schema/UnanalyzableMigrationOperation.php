<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Domain\Schema;

final class UnanalyzableMigrationOperation extends SchemaChange
{
    public function __construct(
        public readonly string $operation,
        ?string $table,
        public readonly ?string $column,
        public readonly string $reason,
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
