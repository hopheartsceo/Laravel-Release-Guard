<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Domain\Application;

final class ModelDescriptor
{
    public function __construct(
        public readonly string $className,
        public readonly ?string $table,
        public readonly ?string $reason,
        public readonly string $file,
        public readonly int $line,
    ) {
    }

    public function hasKnownTable(): bool
    {
        return $this->table !== null;
    }
}
