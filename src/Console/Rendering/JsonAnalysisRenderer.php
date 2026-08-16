<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Console\Rendering;

use Hopheartsceo\ReleaseGuard\Domain\AnalysisResult;
use Hopheartsceo\ReleaseGuard\Domain\Application\DatabaseUsage;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Finding;

final class JsonAnalysisRenderer
{
    public function render(AnalysisResult $result): string
    {
        $payload = [
            'status' => $result->hasDefiniteBlocker()
                ? 'incompatible'
                : 'no_definite_incompatibility_detected',
            'base_revision' => $result->baseRevision,
            'base_application_files' =>
                $result->baseApplicationFileCount,
            'candidate_migration_files' =>
                $result->candidateMigrationFileCount,
            'findings' => array_map(
                fn (Finding $finding): array =>
                    $this->finding($finding),
                $result->findings,
            ),
        ];

        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR
            | JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(Finding $finding): array
    {
        return [
            'code' => $finding->code,
            'severity' => $finding->severity->value,
            'confidence' => $finding->confidence->value,
            'table' => $finding->table,
            'column' => $finding->column,
            'usage' => $finding->usage === null
                ? null
                : [
                    'file' => $finding->usage->file,
                    'line' => $finding->usage->line,
                    'operation' =>
                        $this->operation($finding->usage),
                ],
            'change' => [
                'file' => $finding->change->file,
                'line' => $finding->change->line,
            ],
        ];
    }

    private function operation(
        DatabaseUsage $usage,
    ): ?string {
        if (! property_exists($usage, 'operation')) {
            return null;
        }

        $operation = $usage->operation;

        return is_string($operation)
            ? $operation
            : null;
    }
}
