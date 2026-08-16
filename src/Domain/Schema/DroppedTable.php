<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Domain\Schema;

final class DroppedTable extends SchemaChange
{
    public function __construct(
        string $table,
        public readonly bool $ifExists,
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
