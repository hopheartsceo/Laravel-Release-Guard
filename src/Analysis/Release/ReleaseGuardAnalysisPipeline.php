<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Analysis\Release;

use Hopheartsceo\ReleaseGuard\Analysis\Application\DatabaseUsageAnalyzer;
use Hopheartsceo\ReleaseGuard\Analysis\Application\EloquentUsageAnalyzer;
use Hopheartsceo\ReleaseGuard\Analysis\Application\ModelIndexService;
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
        ?ModelIndexService $modelIndexService = null,
        ?EloquentUsageAnalyzer $eloquentUsageAnalyzer = null,
    ) {
        $this->modelIndexService = $modelIndexService
            ?? new ModelIndexService();

        $this->eloquentUsageAnalyzer = $eloquentUsageAnalyzer
            ?? new EloquentUsageAnalyzer();
    }

    private readonly ModelIndexService $modelIndexService;

    private readonly EloquentUsageAnalyzer $eloquentUsageAnalyzer;

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

        $modelIndex = $this->modelIndexService->build(
            $baseFiles,
        );

        $usages = [];

        foreach ($baseFiles as $file) {
            $queryBuilderSnapshot =
                $this->databaseUsageAnalyzer->analyze(
                    source: $file->contents,
                    file: $file->path,
                );

            foreach (
                $queryBuilderSnapshot->usages()
                as $usage
            ) {
                $usages[] = $usage;
            }

            $eloquentSnapshot =
                $this->eloquentUsageAnalyzer->analyze(
                    source: $file->contents,
                    file: $file->path,
                    models: $modelIndex,
                );

            foreach (
                $eloquentSnapshot->usages()
                as $usage
            ) {
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
