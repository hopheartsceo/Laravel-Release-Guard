<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Compatibility\Rules;

use Hopheartsceo\ReleaseGuard\Compatibility\Rules\RequiredColumnBreaksBaseWritesRule;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Application\WriteUsage;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Confidence;
use Hopheartsceo\ReleaseGuard\Domain\Finding\Severity;
use Hopheartsceo\ReleaseGuard\Domain\Schema\AddedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\SchemaDelta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequiredColumnBreaksBaseWritesInsertVariantsTest extends TestCase
{
    public function test_insert_get_id_missing_required_column_blocks(): void
    {
        $findings = $this->evaluate(
            operation: 'insertGetId',
            columns: ['name', 'email'],
        );

        $this->assertCount(1, $findings);
        $this->assertSame('DB005', $findings[0]->code);
    }

    #[DataProvider('eloquentCreateOperations')]
    public function test_eloquent_create_operations_are_warning_unknown(
        string $operation,
    ): void {
        $findings = $this->evaluate(
            operation: $operation,
            columns: ['name', 'email'],
        );

        $this->assertCount(1, $findings);
        $this->assertSame('DB005', $findings[0]->code);
        $this->assertSame(
            Severity::WARNING,
            $findings[0]->severity,
        );
        $this->assertSame(
            Confidence::UNKNOWN,
            $findings[0]->confidence,
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function eloquentCreateOperations(): array
    {
        return [
            'create' => ['create'],
            'forceCreate' => ['forceCreate'],
            'createQuietly' => ['createQuietly'],
            'forceCreateQuietly' => ['forceCreateQuietly'],
        ];
    }

    public function test_insert_or_ignore_remains_non_definite(): void
    {
        $findings = $this->evaluate(
            operation: 'insertOrIgnore',
            columns: ['name', 'email'],
        );

        $this->assertSame([], $findings);
    }

    /**
     * @param list<string>|null $columns
     * @return list<object>
     */
    private function evaluate(
        string $operation,
        ?array $columns,
    ): array {
        $change = new AddedColumn(
            table: 'users',
            column: 'tenant_id',
            type: 'unsignedBigInteger',
            nullable: false,
            hasDefault: false,
            usesCurrent: false,
            file: 'database/migrations/add_tenant_id.php',
            line: 12,
        );

        $usage = new WriteUsage(
            table: 'users',
            operation: $operation,
            columns: $columns,
            reason: null,
            file: 'app/Services/UserWriter.php',
            line: 20,
        );

        return (new RequiredColumnBreaksBaseWritesRule())->evaluate(
            new SchemaDelta([$change]),
            new ApplicationSnapshot([$usage]),
        );
    }
}
