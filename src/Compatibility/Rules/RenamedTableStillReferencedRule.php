<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Compatibility\Rules;

use Hopheartsceo\ReleaseGuard\Compatibility\Contracts\CompatibilityRuleInterface;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Finding;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Domain\Schema\RenamedTable;
use Hopheartsceo\ReleaseGuard\Domain\Schema\SchemaDelta;

final class RenamedTableStillReferencedRule implements CompatibilityRuleInterface
{
    /**
     * @return list<Finding>
     */
    public function evaluate(
        SchemaDelta $schemaDelta,
        ApplicationSnapshot $application,
    ): array {
        $findings = [];

        foreach ($schemaDelta->changes() as $change) {
            if (! $change instanceof RenamedTable) {
                continue;
            }

            foreach ($application->usages() as $usage) {
                if (
                    $usage->table === null
                    || $usage->table !== $change->from
                ) {
                    continue;
                }

                $findings[] = new Finding(
                    code: 'DB004',
                    severity: Severity::BLOCKER,
                    confidence: Confidence::DEFINITE,
                    table: $change->from,
                    column: null,
                    usage: $usage,
                    change: $change,
                );
            }
        }

        return $findings;
    }
}
