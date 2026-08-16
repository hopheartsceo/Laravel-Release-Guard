<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Domain\Finding;

use Hopheartsceo\ReleaseGuard\Domain\Application\DatabaseUsage;
use Hopheartsceo\ReleaseGuard\Domain\Schema\SchemaChange;

final class Finding
{
    public function __construct(
        public readonly string $code,
        public readonly Severity $severity,
        public readonly Confidence $confidence,
        public readonly string $table,
        public readonly ?string $column,
        public readonly DatabaseUsage $usage,
        public readonly SchemaChange $change,
    ) {
    }
}
