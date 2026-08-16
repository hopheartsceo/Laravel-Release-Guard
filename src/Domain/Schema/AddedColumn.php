<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Domain\Schema;

final class AddedColumn extends SchemaChange
{
    public function __construct(
        string $table,
        public readonly string $column,
        public readonly string $type,
        public readonly bool $nullable,
        public readonly bool $hasDefault,
        public readonly bool $usesCurrent,
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
