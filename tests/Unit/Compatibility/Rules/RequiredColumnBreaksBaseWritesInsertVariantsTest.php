<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Compatibility\Rules;

use Hopheartsceo\ReleaseGuard\Compatibility\Rules\RequiredColumnBreaksBaseWritesRule;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Application\WriteUsage;
use Hopheartsceo\ReleaseGuard\Domain\Schema\AddedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\SchemaDelta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequiredColumnBreaksBaseWritesInsertVariantsTest extends TestCase
{
    #[DataProvider('insertionOperations')]
    public function test_insert_variants_missing_required_column_block(
        string $operation,
    ): void {
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
            columns: ['name', 'email'],
            reason: null,
            file: 'app/Services/UserWriter.php',
            line: 20,
        );

        $findings = (new RequiredColumnBreaksBaseWritesRule())->evaluate(
            new SchemaDelta([$change]),
            new ApplicationSnapshot([$usage]),
        );

        $this->assertCount(1, $findings);
        $this->assertSame('DB005', $findings[0]->code);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function insertionOperations(): array
    {
        return [
            'insertOrIgnore' => ['insertOrIgnore'],
            'insertGetId' => ['insertGetId'],
            'create' => ['create'],
            'forceCreate' => ['forceCreate'],
            'createQuietly' => ['createQuietly'],
            'forceCreateQuietly' => ['forceCreateQuietly'],
        ];
    }
}
