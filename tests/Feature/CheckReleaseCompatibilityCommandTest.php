<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Feature;

use Hopheartsceo\ReleaseGuard\Infrastructure\Git\GitRepositoryService;
use Hopheartsceo\ReleaseGuard\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

final class CheckReleaseCompatibilityCommandTest extends TestCase
{
    private string $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = sys_get_temp_dir()
            .'/release-guard-command-'
            .bin2hex(random_bytes(8));

        mkdir($this->repository, 0777, true);
        mkdir($this->repository.'/app', 0777, true);
        mkdir(
            $this->repository.'/database/migrations',
            0777,
            true,
        );

        $this->git('init', '-q');

        file_put_contents(
            $this->repository.'/app/UserLookup.php',
            <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('users')
    ->where('legacy_status', 'active')
    ->get();
PHP,
        );

        $this->git('add', '.');
        $this->commit('Base release');

        file_put_contents(
            $this->migrationPath(),
            $this->dropColumnMigration('legacy_status'),
        );

        $this->git('add', '.');
        $this->commit('Candidate release');

        $this->app->instance(
            GitRepositoryService::class,
            new GitRepositoryService($this->repository),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->repository);

        parent::tearDown();
    }

    public function test_definite_blocker_returns_exit_code_one(): void
    {
        $exitCode = Artisan::call(
            'release-guard:check',
            [
                '--against' => 'HEAD~1',
            ],
        );

        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            '[BLOCKER][DEFINITE][DB001]',
            $output,
        );
        $this->assertStringContainsString(
            'users.legacy_status',
            $output,
        );
    }

    public function test_no_definite_incompatibility_returns_zero(): void
    {
        file_put_contents(
            $this->migrationPath(),
            $this->dropColumnMigration('unused_column'),
        );

        $exitCode = Artisan::call(
            'release-guard:check',
            [
                '--against' => 'HEAD~1',
            ],
        );

        $this->assertSame(0, $exitCode);

        $this->assertStringContainsString(
            'No incompatible changes detected within the analyzed scope.',
            Artisan::output(),
        );
    }

    public function test_json_output_is_machine_readable(): void
    {
        $exitCode = Artisan::call(
            'release-guard:check',
            [
                '--against' => 'HEAD~1',
                '--format' => 'json',
            ],
        );

        $payload = json_decode(
            Artisan::output(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(1, $exitCode);
        $this->assertSame(
            'incompatible',
            $payload['status'],
        );
        $this->assertSame(
            'DB001',
            $payload['findings'][0]['code'],
        );
        $this->assertSame(
            'definite',
            $payload['findings'][0]['confidence'],
        );
        $this->assertSame(
            'blocker',
            $payload['findings'][0]['severity'],
        );
    }

    public function test_missing_against_returns_exit_code_two(): void
    {
        $exitCode = Artisan::call(
            'release-guard:check',
        );

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString(
            '--against option is required',
            Artisan::output(),
        );
    }

    public function test_invalid_format_returns_exit_code_two(): void
    {
        $exitCode = Artisan::call(
            'release-guard:check',
            [
                '--against' => 'HEAD~1',
                '--format' => 'xml',
            ],
        );

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString(
            'Invalid output format. Use console or json.',
            Artisan::output(),
        );
    }

    public function test_invalid_revision_returns_exit_code_two(): void
    {
        $exitCode = Artisan::call(
            'release-guard:check',
            [
                '--against' => 'revision-that-does-not-exist',
            ],
        );

        $output = Artisan::output();

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString(
            'Analysis failed: Git operation [rev-parse] failed.',
            $output,
        );
        $this->assertStringNotContainsString(
            'git rev-parse',
            $output,
        );
        $this->assertStringNotContainsString(
            $this->repository,
            $output,
        );
    }

    public function test_invalid_revision_json_returns_exit_code_two_with_machine_readable_error(): void
    {
        $exitCode = Artisan::call(
            'release-guard:check',
            [
                '--against' => 'revision-that-does-not-exist',
                '--format' => 'json',
            ],
        );

        $payload = json_decode(
            Artisan::output(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(2, $exitCode);
        $this->assertSame(
            'error',
            $payload['status'],
        );
        $this->assertSame(
            'Analysis failed: Git operation [rev-parse] failed.',
            $payload['error'],
        );
        $this->assertStringNotContainsString(
            $this->repository,
            $payload['error'],
        );
    }

    private function migrationPath(): string
    {
        return $this->repository
            .'/database/migrations/'
            .'2026_08_16_000001_drop_column.php';
    }

    private function dropColumnMigration(
        string $column,
    ): string {
        return <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint \$table): void {
            \$table->dropColumn('{$column}');
        });
    }
};
PHP;
    }

    private function commit(string $message): void
    {
        $process = new Process([
            'git',
            '-c',
            'user.name=Release Guard Tests',
            '-c',
            'user.email=release-guard@example.test',
            'commit',
            '-q',
            '-m',
            $message,
        ], $this->repository);

        $process->mustRun();
    }

    private function git(string ...$arguments): void
    {
        $process = new Process(
            ['git', ...$arguments],
            $this->repository,
        );

        $process->mustRun();
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;

            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
