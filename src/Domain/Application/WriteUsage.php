<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Domain\Application;

final class WriteUsage extends DatabaseUsage
{
    /**
     * @param list<string>|null $columns
     */
    public function __construct(
        ?string $table,
        public readonly string $operation,
        public readonly ?array $columns,
        public readonly ?string $reason,
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
