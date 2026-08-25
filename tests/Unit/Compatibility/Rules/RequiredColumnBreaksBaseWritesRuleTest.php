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
use PHPUnit\Framework\TestCase;

final class RequiredColumnBreaksBaseWritesRuleTest extends TestCase
{
    public function test_required_column_missing_from_base_insert_is_a_definite_blocker(): void
    {
        $change = $this->addedColumn(
            nullable: false,
            hasDefault: false,
        );

        $write = $this->writeUsage([
            'name',
            'email',
        ]);

        $findings = (new RequiredColumnBreaksBaseWritesRule())->evaluate(
            new SchemaDelta([$change]),
            new ApplicationSnapshot([$write]),
        );

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB005', $finding->code);
        $this->assertSame(Severity::BLOCKER, $finding->severity);
        $this->assertSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('users', $finding->table);
        $this->assertSame('country_code', $finding->column);
        $this->assertSame($write, $finding->usage);
        $this->assertSame($change, $finding->change);
    }

    public function test_nullable_added_column_does_not_break_base_insert(): void
    {
        $findings = (new RequiredColumnBreaksBaseWritesRule())->evaluate(
            new SchemaDelta([
                $this->addedColumn(
                    nullable: true,
                    hasDefault: false,
                ),
            ]),
            new ApplicationSnapshot([
                $this->writeUsage([
                    'name',
                    'email',
                ]),
            ]),
        );

        $this->assertSame([], $findings);
    }

    public function test_added_column_with_database_default_does_not_break_base_insert(): void
    {
        $findings = (new RequiredColumnBreaksBaseWritesRule())->evaluate(
            new SchemaDelta([
                $this->addedColumn(
                    nullable: false,
                    hasDefault: true,
                ),
            ]),
            new ApplicationSnapshot([
                $this->writeUsage([
                    'name',
                    'email',
                ]),
            ]),
        );

        $this->assertSame([], $findings);
    }

    public function test_base_insert_that_supplies_the_new_column_does_not_break(): void
    {
        $findings = (new RequiredColumnBreaksBaseWritesRule())->evaluate(
            new SchemaDelta([
                $this->addedColumn(
                    nullable: false,
                    hasDefault: false,
                ),
            ]),
            new ApplicationSnapshot([
                $this->writeUsage([
                    'name',
                    'email',
                    'country_code',
                ]),
            ]),
        );

        $this->assertSame([], $findings);
    }

    public function test_unknown_base_insert_payload_is_warning_unknown(): void
    {
        $write = new WriteUsage(
            table: 'users',
            operation: 'insert',
            columns: null,
            reason: 'dynamic_payload',
            file: 'app/Services/UserCreator.php',
            line: 18,
        );

        $findings = (new RequiredColumnBreaksBaseWritesRule())->evaluate(
            new SchemaDelta([
                $this->addedColumn(
                    nullable: false,
                    hasDefault: false,
                ),
            ]),
            new ApplicationSnapshot([
                $write,
            ]),
        );

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB005', $finding->code);
        $this->assertSame(
            Severity::WARNING,
            $finding->severity,
        );
        $this->assertSame(
            Confidence::UNKNOWN,
            $finding->confidence,
        );
        $this->assertSame('users', $finding->table);
        $this->assertSame(
            'country_code',
            $finding->column,
        );
        $this->assertSame($write, $finding->usage);
    }

    public function test_write_to_another_table_does_not_break(): void
    {
        $findings = (new RequiredColumnBreaksBaseWritesRule())->evaluate(
            new SchemaDelta([
                $this->addedColumn(
                    nullable: false,
                    hasDefault: false,
                ),
            ]),
            new ApplicationSnapshot([
                new WriteUsage(
                    table: 'profiles',
                    operation: 'insert',
                    columns: ['user_id', 'bio'],
                    reason: null,
                    file: 'app/Services/ProfileCreator.php',
                    line: 14,
                ),
            ]),
        );

        $this->assertSame([], $findings);
    }

    public function test_fresh_eloquent_save_with_unknown_columns_is_warning_unknown_not_definite(): void
    {
        $write = new WriteUsage(
            table: 'users',
            operation: 'eloquent_fresh_save',
            columns: null,
            reason: 'eloquent_fresh_save_semantics',
            file: 'app/Services/UserCreator.php',
            line: 18,
        );

        $findings = (new RequiredColumnBreaksBaseWritesRule())->evaluate(
            new SchemaDelta([
                $this->addedColumn(
                    nullable: false,
                    hasDefault: false,
                ),
            ]),
            new ApplicationSnapshot([
                $write,
            ]),
        );

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('DB005', $finding->code);
        $this->assertSame(Severity::WARNING, $finding->severity);
        $this->assertSame(Confidence::UNKNOWN, $finding->confidence);
        $this->assertNotSame(Severity::BLOCKER, $finding->severity);
        $this->assertNotSame(Confidence::DEFINITE, $finding->confidence);
        $this->assertSame('users', $finding->table);
        $this->assertSame('country_code', $finding->column);
        $this->assertSame($write, $finding->usage);
    }

    private function addedColumn(
        bool $nullable,
        bool $hasDefault,
    ): AddedColumn {
        return new AddedColumn(
            table: 'users',
            column: 'country_code',
            type: 'string',
            nullable: $nullable,
            hasDefault: $hasDefault,
            usesCurrent: false,
            file: 'database/migrations/add_country_code_to_users.php',
            line: 12,
        );
    }

    /**
     * @param list<string> $columns
     */
    private function writeUsage(array $columns): WriteUsage
    {
        return new WriteUsage(
            table: 'users',
            operation: 'insert',
            columns: $columns,
            reason: null,
            file: 'app/Services/UserCreator.php',
            line: 18,
        );
    }
}
