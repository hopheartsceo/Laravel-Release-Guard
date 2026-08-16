<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Analysis\Release;

use Hopheartsceo\ReleaseGuard\Analysis\Application\DatabaseUsageAnalyzer;
use Hopheartsceo\ReleaseGuard\Analysis\Migrations\MigrationAnalyzer;
use Hopheartsceo\ReleaseGuard\Compatibility\CompatibilityEngine;
use Hopheartsceo\ReleaseGuard\Domain\AnalysisResult;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Schema\SchemaDelta;
use Hopheartsceo\ReleaseGuard\Infrastructure\Git\GitRepositoryService;
use Hopheartsceo\ReleaseGuard\Source\BaseRevisionSourceProvider;
use Hopheartsceo\ReleaseGuard\Source\CandidateSourceProvider;

final class ReleaseGuardAnalysisPipeline
{
    public function __construct(
        private readonly GitRepositoryService $git,
        private readonly BaseRevisionSourceProvider $baseSources,
        private readonly CandidateSourceProvider $candidateSources,
        private readonly DatabaseUsageAnalyzer $databaseUsageAnalyzer,
        private readonly MigrationAnalyzer $migrationAnalyzer,
        private readonly CompatibilityEngine $compatibilityEngine,
    ) {
    }

    /**
     * @param list<string> $applicationPaths
     * @param list<string> $migrationPaths
     */
    public function analyzeAgainst(
        string $revision,
        array $applicationPaths = ['app'],
        array $migrationPaths = ['database/migrations'],
    ): AnalysisResult {
        $baseRevision = $this->git->resolveRevision($revision);

        $baseFiles = $this->baseSources->applicationFiles(
            revision: $baseRevision,
            paths: $applicationPaths,
        );

        $candidateMigrations = $this->candidateSources
            ->migrationFilesAgainst(
                baseRevision: $baseRevision,
                paths: $migrationPaths,
            );

        $usages = [];

        foreach ($baseFiles as $file) {
            $snapshot = $this->databaseUsageAnalyzer->analyze(
                source: $file->contents,
                file: $file->path,
            );

            foreach ($snapshot->usages() as $usage) {
                $usages[] = $usage;
            }
        }

        $changes = [];

        foreach ($candidateMigrations as $file) {
            $delta = $this->migrationAnalyzer->analyze(
                source: $file->contents,
                file: $file->path,
            );

            foreach ($delta->changes() as $change) {
                $changes[] = $change;
            }
        }

        $findings = $this->compatibilityEngine->analyze(
            new SchemaDelta($changes),
            new ApplicationSnapshot($usages),
        );

        return new AnalysisResult(
            baseRevision: $baseRevision,
            baseApplicationFileCount: count($baseFiles),
            candidateMigrationFileCount: count($candidateMigrations),
            findings: $findings,
        );
    }
}
