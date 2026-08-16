<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Domain\Schema;

final class SchemaDelta
{
    /**
     * @param list<SchemaChange> $changes
     */
    public function __construct(
        private readonly array $changes = [],
    ) {
    }

    /**
     * @return list<SchemaChange>
     */
    public function changes(): array
    {
        return $this->changes;
    }
}
