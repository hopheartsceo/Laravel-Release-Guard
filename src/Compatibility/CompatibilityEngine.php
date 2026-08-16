<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Compatibility;

use Hopheartsceo\ReleaseGuard\Compatibility\Contracts\CompatibilityRuleInterface;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Finding;
use Hopheartsceo\ReleaseGuard\Domain\Schema\SchemaDelta;

final class CompatibilityEngine
{
    /**
     * @param list<CompatibilityRuleInterface> $rules
     */
    public function __construct(
        private readonly array $rules,
    ) {
    }

    /**
     * @return list<Finding>
     */
    public function analyze(
        SchemaDelta $schemaDelta,
        ApplicationSnapshot $application,
    ): array {
        $findings = [];

        foreach ($this->rules as $rule) {
            foreach ($rule->evaluate($schemaDelta, $application) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }
}
