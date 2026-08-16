<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Analysis\Migrations;

use Hopheartsceo\ReleaseGuard\Domain\Schema\AddedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\UnanalyzableMigrationOperation;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;

final class BlueprintAddedColumnAnalyzer
{
    /**
     * @var list<string>
     */
    private const COLUMN_TYPES = [
        'bigInteger',
        'boolean',
        'char',
        'date',
        'dateTime',
        'decimal',
        'double',
        'float',
        'foreignId',
        'integer',
        'json',
        'longText',
        'mediumInteger',
        'mediumText',
        'smallInteger',
        'string',
        'text',
        'time',
        'timestamp',
        'tinyInteger',
        'unsignedBigInteger',
        'unsignedInteger',
        'uuid',
    ];

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     * @return list<AddedColumn|UnanalyzableMigrationOperation>
     */
    public function analyze(
        array $statements,
        string $blueprintVariable,
        ?string $table,
        string $file,
    ): array {
        $changes = [];

        foreach ($statements as $statement) {
            if (
                ! $statement instanceof Expression
                || ! $statement->expr instanceof MethodCall
            ) {
                continue;
            }

            $change = $this->analyzeChain(
                call: $statement->expr,
                blueprintVariable: $blueprintVariable,
                table: $table,
                file: $file,
            );

            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    private function analyzeChain(
        MethodCall $call,
        string $blueprintVariable,
        ?string $table,
        string $file,
    ): AddedColumn|UnanalyzableMigrationOperation|null {
        $nullable = false;
        $nullableKnown = true;
        $nullableSeen = false;

        $hasDefault = false;
        $usesCurrent = false;

        $current = $call;

        while ($current instanceof MethodCall) {
            if (! $current->name instanceof Identifier) {
                return null;
            }

            $method = $current->name->toString();

            if ($method === 'nullable' && ! $nullableSeen) {
                $nullableSeen = true;

                $nullableValue = $this->nullableValue($current);

                if ($nullableValue === null) {
                    $nullableKnown = false;
                } else {
                    $nullable = $nullableValue;
                }
            }

            if ($method === 'default') {
                $hasDefault = true;
            }

            if ($method === 'useCurrent') {
                $usesCurrent = true;
                $hasDefault = true;
            }

            if (
                $current->var instanceof Variable
                && $current->var->name === $blueprintVariable
                && in_array($method, self::COLUMN_TYPES, true)
            ) {
                $column = $this->literalColumnName($current);

                if ($table === null || $column === null) {
                    return new UnanalyzableMigrationOperation(
                        operation: 'addColumn',
                        table: $table,
                        column: $column,
                        reason: $this->unknownLocationReason(
                            table: $table,
                            column: $column,
                        ),
                        file: $file,
                        line: $current->getStartLine(),
                    );
                }

                if (! $nullableKnown) {
                    return new UnanalyzableMigrationOperation(
                        operation: 'addColumn',
                        table: $table,
                        column: $column,
                        reason: 'dynamic_nullable_modifier',
                        file: $file,
                        line: $current->getStartLine(),
                    );
                }

                return new AddedColumn(
                    table: $table,
                    column: $column,
                    type: $method,
                    nullable: $nullable,
                    hasDefault: $hasDefault,
                    usesCurrent: $usesCurrent,
                    file: $file,
                    line: $current->getStartLine(),
                );
            }

            if (! $current->var instanceof MethodCall) {
                return null;
            }

            $current = $current->var;
        }

        return null;
    }

    private function literalColumnName(MethodCall $call): ?string
    {
        $argument = $call->args[0]->value ?? null;

        if (! $argument instanceof String_) {
            return null;
        }

        return $argument->value;
    }

    private function nullableValue(MethodCall $call): ?bool
    {
        if ($call->args === []) {
            return true;
        }

        $argument = $call->args[0]->value ?? null;

        if (! $argument instanceof ConstFetch) {
            return null;
        }

        $value = strtolower($argument->name->toString());

        if ($value === 'true') {
            return true;
        }

        if ($value === 'false') {
            return false;
        }

        return null;
    }

    private function unknownLocationReason(
        ?string $table,
        ?string $column,
    ): string {
        if ($table === null && $column === null) {
            return 'dynamic_table_and_column';
        }

        if ($table === null) {
            return 'dynamic_table';
        }

        return 'dynamic_column';
    }
}
