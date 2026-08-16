<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Domain\Schema;

final class RenamedTable extends SchemaChange
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        string $file,
        int $line,
    ) {
        parent::__construct(
            table: $from,
            file: $file,
            line: $line,
        );
    }
}
