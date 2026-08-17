<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Analysis\Application;

use Hopheartsceo\ReleaseGuard\Analysis\Ast\PhpAstParserService;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Application\ColumnUsage;
use Hopheartsceo\ReleaseGuard\Domain\Application\TableUsage;
use Hopheartsceo\ReleaseGuard\Domain\Application\WriteUsage;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;

final class DatabaseUsageAnalyzer
{
    /**
     * @var list<string>
     */
    private const FIRST_ARGUMENT_COLUMN_METHODS = [
        'where',
        'orWhere',
        'whereIn',
        'orWhereIn',
        'whereNotIn',
        'orWhereNotIn',
        'whereNull',
        'orWhereNull',
        'whereNotNull',
        'orWhereNotNull',
        'whereBetween',
        'orWhereBetween',
        'whereNotBetween',
        'orWhereNotBetween',
        'whereDate',
        'whereMonth',
        'whereDay',
        'whereYear',
        'whereTime',
        'whereJsonContains',
        'whereJsonDoesntContain',
        'whereJsonLength',
        'whereIntegerInRaw',
        'whereIntegerNotInRaw',
        'whereLike',
        'orWhereLike',
        'whereNotLike',
        'orWhereNotLike',
        'orderBy',
        'orderByDesc',
        'having',
        'orHaving',
        'havingBetween',
        'increment',
        'decrement',
    ];

    /**
     * @var list<string>
     */
    private const MULTI_COLUMN_METHODS = [
        'select',
        'addSelect',
        'groupBy',
    ];

    /**
     * @var list<string>
     */
    private const INSERT_METHODS = [
        'insert',
        'insertOrIgnore',
        'insertGetId',
    ];

    /**
     * @var list<string>
     */
    private const PAYLOAD_WRITE_METHODS = [
        'update',
    ];

    /**
     * @var list<string>
     */
    private const TABLE_TERMINALS = [
        'get',
        'first',
        'firstOrFail',
        'sole',
        'find',
        'pluck',
        'value',
        'exists',
        'doesntExist',
        'existsOr',
        'doesntExistOr',
        'count',
        'sum',
        'avg',
        'average',
        'min',
        'max',
        'delete',
        'cursor',
        'lazy',
        'lazyById',
        'chunk',
        'chunkById',
        'paginate',
        'simplePaginate',
        'cursorPaginate',
    ];

    private readonly PhpAstParserService $parser;

    private readonly NodeFinder $finder;

    public function __construct(
        ?PhpAstParserService $parser = null,
        ?NodeFinder $finder = null,
    ) {
        $this->parser = $parser ?? new PhpAstParserService();
        $this->finder = $finder ?? new NodeFinder();
    }

    public function analyze(
        string $source,
        string $file,
    ): ApplicationSnapshot {
        $statements = $this->parser->parse($source);
        $usages = [];

        $methodCalls = $this->finder->findInstanceOf(
            $statements,
            MethodCall::class,
        );

        foreach ($methodCalls as $call) {
            $method = $this->methodName($call);

            if ($method === null) {
                continue;
            }

            if (
                in_array($method, self::INSERT_METHODS, true)
                || in_array(
                    $method,
                    self::PAYLOAD_WRITE_METHODS,
                    true,
                )
            ) {
                if (! $this->isQueryBuilderCall($call)) {
                    continue;
                }

                $usages[] = $this->analyzePayloadWrite(
                    call: $call,
                    operation: $method,
                    file: $file,
                );

                continue;
            }

            $columns = $this->literalReferencedColumns($call);

            if ($columns !== []) {
                $table = $this->queryBuilderTable($call);

                if ($table === null) {
                    continue;
                }

                foreach ($columns as $column) {
                    $usages[] = new ColumnUsage(
                        table: $table,
                        column: $column,
                        operation: $method,
                        file: $file,
                        line: $call->getStartLine(),
                    );
                }

                continue;
            }

            if (
                in_array($method, self::TABLE_TERMINALS, true)
            ) {
                $usage = $this->analyzeTableTerminal(
                    call: $call,
                    operation: $method,
                    file: $file,
                );

                if ($usage !== null) {
                    $usages[] = $usage;
                }
            }
        }

        return new ApplicationSnapshot($usages);
    }

    private function analyzePayloadWrite(
        MethodCall $call,
        string $operation,
        string $file,
    ): WriteUsage {
        $table = $this->queryBuilderTable($call);
        $payload = $call->args[0]->value ?? null;

        $columns = null;
        $reason = null;

        if (
            $payload instanceof Array_
            && $payload->items === []
            && in_array(
                $operation,
                ['insert', 'insertOrIgnore'],
                true,
            )
        ) {
            $reason = 'empty_insert_noop';
        } elseif ($payload instanceof Array_) {
            $columns = $this->literalWriteColumns($payload);

            if ($columns === null) {
                $reason = 'dynamic_column_key';
            }
        } else {
            $reason = 'dynamic_payload';
        }

        if ($table === null) {
            $reason = $this->withDynamicTable($reason);
        }

        return new WriteUsage(
            table: $table,
            operation: $operation,
            columns: $columns,
            reason: $reason,
            file: $file,
            line: $call->getStartLine(),
        );
    }

    private function analyzeTableTerminal(
        MethodCall $call,
        string $operation,
        string $file,
    ): ?TableUsage {
        if (! $this->isQueryBuilderCall($call)) {
            return null;
        }

        if ($this->hasDefiniteColumnUsageInChain($call)) {
            return null;
        }

        $table = $this->queryBuilderTable($call);

        if ($table === null) {
            return null;
        }

        return new TableUsage(
            table: $table,
            operation: $operation,
            file: $file,
            line: $call->getStartLine(),
        );
    }

    /**
     * @return list<string>
     */
    private function literalReferencedColumns(
        MethodCall $call,
    ): array {
        $method = $this->methodName($call);

        if ($method === null || ! $this->isQueryBuilderCall($call)) {
            return [];
        }

        if (
            in_array(
                $method,
                self::FIRST_ARGUMENT_COLUMN_METHODS,
                true,
            )
        ) {
            return $this->literalColumnsAt($call, 0);
        }

        if (
            in_array($method, self::MULTI_COLUMN_METHODS, true)
        ) {
            return $this->literalColumnsFromArguments($call);
        }

        if ($method === 'whereColumn') {
            $columns = $this->literalColumnsAt($call, 0);

            $secondPosition = isset($call->args[2])
                ? 2
                : 1;

            return array_values(array_unique([
                ...$columns,
                ...$this->literalColumnsAt(
                    $call,
                    $secondPosition,
                ),
            ]));
        }

        if ($method === 'pluck') {
            return array_values(array_unique([
                ...$this->literalColumnsAt($call, 0),
                ...$this->literalColumnsAt($call, 1),
            ]));
        }

        if ($method === 'value') {
            return $this->literalColumnsAt($call, 0);
        }

        if ($method === 'find') {
            return $this->literalColumnsAt($call, 1);
        }

        if (
            in_array(
                $method,
                ['get', 'first', 'firstOrFail', 'sole'],
                true,
            )
        ) {
            return $this->literalColumnsFromArguments($call);
        }

        if (
            in_array(
                $method,
                ['count', 'sum', 'avg', 'average', 'min', 'max'],
                true,
            )
        ) {
            $columns = $this->literalColumnsAt($call, 0);

            return array_values(array_filter(
                $columns,
                static fn (string $column): bool =>
                    $column !== '*',
            ));
        }

        return [];
    }

    private function hasDefiniteColumnUsageInChain(
        MethodCall $call,
    ): bool {
        $current = $call->var;

        while ($current instanceof MethodCall) {
            if ($this->literalReferencedColumns($current) !== []) {
                return true;
            }

            $current = $current->var;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function literalColumnsFromArguments(
        MethodCall $call,
    ): array {
        $columns = [];

        foreach ($call->args as $argument) {
            foreach (
                $this->literalColumnsFromValue($argument->value)
                as $column
            ) {
                $columns[] = $column;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * @return list<string>
     */
    private function literalColumnsAt(
        MethodCall $call,
        int $position,
    ): array {
        $value = $call->args[$position]->value ?? null;

        return $this->literalColumnsFromValue($value);
    }

    /**
     * @return list<string>
     */
    private function literalColumnsFromValue(
        mixed $value,
    ): array {
        if ($value instanceof String_) {
            return [$value->value];
        }

        if (! $value instanceof Array_) {
            return [];
        }

        $columns = [];

        foreach ($value->items as $item) {
            if (
                $item instanceof ArrayItem
                && $item->value instanceof String_
            ) {
                $columns[] = $item->value->value;
            }
        }

        return $columns;
    }

    /**
     * @return list<string>|null
     */
    private function literalWriteColumns(
        Array_ $array,
    ): ?array {
        if ($array->items === []) {
            return [];
        }

        $associativeColumns = $this->associativeArrayKeys($array);

        if ($associativeColumns !== null) {
            return $associativeColumns;
        }

        $rows = [];

        foreach ($array->items as $item) {
            if (
                ! $item instanceof ArrayItem
                || $item->key !== null
                || ! $item->value instanceof Array_
            ) {
                return null;
            }

            $columns = $this->associativeArrayKeys(
                $item->value,
            );

            if ($columns === null) {
                return null;
            }

            $rows[] = $columns;
        }

        if ($rows === []) {
            return null;
        }

        $expected = $rows[0];

        foreach ($rows as $row) {
            if ($row !== $expected) {
                return null;
            }
        }

        return $expected;
    }

    /**
     * @return list<string>|null
     */
    private function associativeArrayKeys(
        Array_ $array,
    ): ?array {
        $columns = [];

        foreach ($array->items as $item) {
            if (
                ! $item instanceof ArrayItem
                || ! $item->key instanceof String_
            ) {
                return null;
            }

            $columns[] = $item->key->value;
        }

        return $columns;
    }

    private function queryBuilderTable(
        MethodCall $call,
    ): ?string {
        $root = $this->queryBuilderRoot($call);

        if ($root === null) {
            return null;
        }

        return $this->literalStaticStringArgument(
            $root,
            0,
        );
    }

    private function isQueryBuilderCall(
        MethodCall $call,
    ): bool {
        return $this->queryBuilderRoot($call) !== null;
    }

    private function queryBuilderRoot(
        MethodCall $call,
    ): ?StaticCall {
        $current = $call->var;

        while ($current instanceof MethodCall) {
            $current = $current->var;
        }

        if (
            ! $current instanceof StaticCall
            || ! $this->isDbTableCall($current)
        ) {
            return null;
        }

        return $current;
    }

    private function methodName(
        MethodCall $call,
    ): ?string {
        if (! $call->name instanceof Identifier) {
            return null;
        }

        return $call->name->toString();
    }

    private function isDbTableCall(
        StaticCall $call,
    ): bool {
        if (
            ! $call->class instanceof Name
            || ! $call->name instanceof Identifier
        ) {
            return false;
        }

        return $call->class->toString()
                === 'Illuminate\Support\Facades\DB'
            && $call->name->toString() === 'table';
    }

    private function literalStaticStringArgument(
        StaticCall $call,
        int $position,
    ): ?string {
        $argument = $call->args[$position]->value ?? null;

        if (! $argument instanceof String_) {
            return null;
        }

        return $argument->value;
    }

    private function withDynamicTable(
        ?string $reason,
    ): string {
        return match ($reason) {
            'dynamic_payload' =>
                'dynamic_table_and_payload',
            'dynamic_column_key' =>
                'dynamic_table_and_column_key',
            default => 'dynamic_table',
        };
    }
}
