<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Domain;

use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Finding;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;

final class AnalysisResult
{
    /**
     * @param list<Finding> $findings
     */
    public function __construct(
        public readonly string $baseRevision,
        public readonly int $baseApplicationFileCount,
        public readonly int $candidateMigrationFileCount,
        public readonly array $findings,
    ) {
    }

    public function hasDefiniteBlocker(): bool
    {
        foreach ($this->findings as $finding) {
            if (
                $finding->severity === Severity::BLOCKER
                && $finding->confidence === Confidence::DEFINITE
            ) {
                return true;
            }
        }

        return false;
    }
}
