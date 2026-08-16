<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Compatibility\Rules;

use Hopheartsceo\ReleaseGuard\Compatibility\Contracts\CompatibilityRuleInterface;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Application\ColumnUsage;
use Hopheartsceo\ReleaseGuard\Domain\Application\DatabaseUsage;
use Hopheartsceo\ReleaseGuard\Domain\Application\WriteUsage;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Finding;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Domain\Schema\RenamedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\SchemaDelta;

final class RenamedColumnStillReferencedRule implements CompatibilityRuleInterface
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
            if (! $change instanceof RenamedColumn) {
                continue;
            }

            foreach ($application->usages() as $usage) {
                if (! $this->referencesOldColumn($usage, $change)) {
                    continue;
                }

                $findings[] = new Finding(
                    code: 'DB002',
                    severity: Severity::BLOCKER,
                    confidence: Confidence::DEFINITE,
                    table: $change->table,
                    column: $change->from,
                    usage: $usage,
                    change: $change,
                );
            }
        }

        return $findings;
    }

    private function referencesOldColumn(
        DatabaseUsage $usage,
        RenamedColumn $change,
    ): bool {
        if (
            $usage->table === null
            || $usage->table !== $change->table
        ) {
            return false;
        }

        if ($usage instanceof ColumnUsage) {
            return $usage->column === $change->from;
        }

        if ($usage instanceof WriteUsage) {
            return $usage->columns !== null
                && in_array(
                    $change->from,
                    $usage->columns,
                    true,
                );
        }

        return false;
    }
}
