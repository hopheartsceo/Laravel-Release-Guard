<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Domain\Application;

final class ApplicationSnapshot
{
    /**
     * @param list<DatabaseUsage> $usages
     */
    public function __construct(
        private readonly array $usages = [],
    ) {
    }

    /**
     * @return list<DatabaseUsage>
     */
    public function usages(): array
    {
        return $this->usages;
    }
}
