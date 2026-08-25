<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Compatibility\Rules;

use Hopheartsceo\ReleaseGuard\Compatibility\Contracts\CompatibilityRuleInterface;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Application\WriteUsage;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Finding;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Domain\Schema\AddedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\SchemaDelta;

final class RequiredColumnBreaksBaseWritesRule implements CompatibilityRuleInterface
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
            if (! $change instanceof AddedColumn) {
                continue;
            }

            if ($change->nullable || $change->hasDefault) {
                continue;
            }

            foreach ($application->usages() as $usage) {
                if (! $usage instanceof WriteUsage) {
                    continue;
                }

                $definiteInsertOperations = [
                    'insert',
                    'insertGetId',
                ];

                $eloquentCreateOperations = [
                    'create',
                    'forceCreate',
                    'createQuietly',
                    'forceCreateQuietly',
                    'eloquent_fresh_save',
                ];

                if (
                    ! in_array(
                        $usage->operation,
                        $definiteInsertOperations,
                        true,
                    )
                    && ! in_array(
                        $usage->operation,
                        $eloquentCreateOperations,
                        true,
                    )
                ) {
                    continue;
                }

                if (
                    $usage->table === null
                    || $usage->table !== $change->table
                ) {
                    continue;
                }

                if (in_array(
                    $usage->operation,
                    $eloquentCreateOperations,
                    true,
                )) {
                    $findings[] = new Finding(
                        code: 'DB005',
                        severity: Severity::WARNING,
                        confidence: Confidence::UNKNOWN,
                        table: $change->table,
                        column: $change->column,
                        usage: $usage,
                        change: $change,
                    );

                    continue;
                }

                if ($usage->columns === null) {
                    $findings[] = new Finding(
                        code: 'DB005',
                        severity: Severity::WARNING,
                        confidence: Confidence::UNKNOWN,
                        table: $change->table,
                        column: $change->column,
                        usage: $usage,
                        change: $change,
                    );

                    continue;
                }

                if (in_array(
                    $change->column,
                    $usage->columns,
                    true,
                )) {
                    continue;
                }

                $findings[] = new Finding(
                    code: 'DB005',
                    severity: Severity::BLOCKER,
                    confidence: Confidence::DEFINITE,
                    table: $change->table,
                    column: $change->column,
                    usage: $usage,
                    change: $change,
                );
            }
        }

        return $findings;
    }
}
