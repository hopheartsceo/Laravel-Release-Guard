<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Console;

use Hopheartsceo\ReleaseGuard\Analysis\Release\ReleaseGuardAnalysisPipeline;
use Hopheartsceo\ReleaseGuard\Console\Rendering\ConsoleAnalysisRenderer;
use Hopheartsceo\ReleaseGuard\Console\Rendering\JsonAnalysisRenderer;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class CheckReleaseCompatibilityCommand extends Command
{
    protected $signature = 'release-guard:check
        {--against= : Base Git revision to compare against}
        {--format=console : Output format: console or json}';

    protected $description =
        'Check whether a Laravel release is compatible with '
        .'the previous release during deployment.';

    public function handle(
        ReleaseGuardAnalysisPipeline $pipeline,
        ConsoleAnalysisRenderer $consoleRenderer,
        JsonAnalysisRenderer $jsonRenderer,
    ): int {
        $format = strtolower(
            (string) $this->option('format'),
        );

        if (! in_array($format, ['console', 'json'], true)) {
            $this->error(
                'Invalid output format. Use console or json.',
            );

            return self::INVALID;
        }

        $against = trim(
            (string) $this->option('against'),
        );

        if ($against === '') {
            $this->renderError(
                'The --against option is required.',
                $format,
            );

            return self::INVALID;
        }

        try {
            $result = $pipeline->analyzeAgainst(
                revision: $against,
                applicationPaths: $this->configuredPaths(
                    'release-guard.paths.application',
                    ['app'],
                ),
                migrationPaths: $this->configuredPaths(
                    'release-guard.paths.migrations',
                    ['database/migrations'],
                ),
            );

            if ($format === 'json') {
                $this->line(
                    $jsonRenderer->render($result),
                );
            } else {
                foreach (
                    $consoleRenderer->render($result)
                    as $line
                ) {
                    $this->line($line);
                }
            }

            return $result->hasDefiniteBlocker()
                ? self::FAILURE
                : self::SUCCESS;
        } catch (Throwable $exception) {
            $this->renderError(
                'Analysis failed: '.$exception->getMessage(),
                $format,
            );

            return self::INVALID;
        }
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    private function configuredPaths(
        string $key,
        array $default,
    ): array {
        $paths = config($key, $default);

        if (! is_array($paths)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Configuration [%s] must be an array.',
                    $key,
                ),
            );
        }

        foreach ($paths as $path) {
            if (
                ! is_string($path)
                || trim($path) === ''
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Configuration [%s] contains an invalid path.',
                        $key,
                    ),
                );
            }
        }

        return array_values($paths);
    }

    private function renderError(
        string $message,
        string $format,
    ): void {
        if ($format === 'json') {
            $this->line(
                json_encode(
                    [
                        'status' => 'error',
                        'error' => $message,
                    ],
                    JSON_THROW_ON_ERROR
                    | JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES,
                ),
            );

            return;
        }

        $this->error($message);
    }
}
