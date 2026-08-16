<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Console;

use Illuminate\Console\Command;

final class CheckReleaseCompatibilityCommand extends Command
{
    protected $signature = 'release-guard:check
        {--against= : Base Git revision to compare against}
        {--format=console : Output format: console or json}';

    protected $description = 'Check whether a Laravel release is compatible with the previous release during deployment.';

    public function handle(): int
    {
        $this->error('Release compatibility analysis has not been implemented yet.');

        return self::FAILURE;
    }
}
