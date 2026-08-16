<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Console\Rendering;

use Hopheartsceo\ReleaseGuard\Domain\AnalysisResult;

final class ConsoleAnalysisRenderer
{
    /**
     * @return list<string>
     */
    public function render(AnalysisResult $result): array
    {
        $lines = [
            'Laravel Release Guard',
            'Base revision: '.$result->baseRevision,
            'Base application files: '
                .$result->baseApplicationFileCount,
            'Candidate migration files: '
                .$result->candidateMigrationFileCount,
        ];

        if ($result->findings === []) {
            $lines[] = '';
            $lines[] = 'No incompatible changes detected within the analyzed scope.';

            return $lines;
        }

        $lines[] = '';

        foreach ($result->findings as $finding) {
            $subject = $finding->table ?? '<dynamic>';

            if ($finding->column !== null) {
                $subject .= '.'.$finding->column;
            }

            $file = $finding->usage?->file
                ?? $finding->change->file;

            $line = $finding->usage?->line
                ?? $finding->change->line;

            $lines[] = sprintf(
                '[%s][%s][%s] %s — %s:%d',
                strtoupper($finding->severity->value),
                strtoupper($finding->confidence->value),
                $finding->code,
                $subject,
                $file,
                $line,
            );
        }

        $lines[] = '';
        $lines[] = sprintf(
            'Findings: %d',
            count($result->findings),
        );

        return $lines;
    }
}
