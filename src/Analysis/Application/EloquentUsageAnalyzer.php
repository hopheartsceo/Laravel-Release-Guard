<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Analysis\Application;

use Hopheartsceo\ReleaseGuard\Analysis\Ast\PhpAstParserService;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Application\ColumnUsage;
use Hopheartsceo\ReleaseGuard\Domain\Application\ModelDescriptor;
use Hopheartsceo\ReleaseGuard\Domain\Application\ModelIndex;
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

final class EloquentUsageAnalyzer
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
        'whereLike',
        'orWhereLike',
        'whereNotLike',
        'orWhereNotLike',
        'orderBy',
        'orderByDesc',
        'having',
        'orHaving',
        'groupBy',
    ];

    /**
     * @var list<string>
     */
    private const MULTI_COLUMN_METHODS = [
        'select',
        'addSelect',
    ];

    /**
     * These operations can create a new database row.
     *
     * @var list<string>
     */
    private const INSERT_WRITE_METHODS = [
        'insert',
        'insertOrIgnore',
        'insertGetId',
        'create',
        'forceCreate',
        'createQuietly',
        'forceCreateQuietly',
    ];

    /**
     * Model creation operations whose final SQL columns may be
     * affected by model defaults, mass assignment, mutators,
     * casts, and model lifecycle behavior.
     *
     * @var list<string>
     */
    private const MODEL_CREATE_METHODS = [
        'create',
        'forceCreate',
        'createQuietly',
        'forceCreateQuietly',
    ];

    /**
     * Model instance methods that must not be promoted to
     * Query Builder usage when written as direct static calls.
     *
     * @var list<string>
     */
    private const DIRECT_STATIC_MODEL_METHODS_TO_IGNORE = [
        'update',
        'delete',
    ];

    /**
     * @var list<string>
     */
    private const UPDATE_WRITE_METHODS = [
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
        'findOrFail',
        'pluck',
        'value',
        'exists',
        'doesntExist',
        'count',
        'sum',
        'avg',
        'average',
        'min',
        'max',
        'delete',
        'cursor',
        'lazy',
        'paginate',
        'simplePaginate',
        'cursorPaginate',
    ];

    /**
     * Static model calls that are known to return an
     * Eloquent query builder.
     *
     * @var list<string>
     */
    private const QUERY_ROOT_STATIC_METHODS = [
        'query',
        'newQuery',
        'newModelQuery',
        'newQueryWithoutRelationships',
        'newQueryWithoutScopes',
        'on',
        'onWriteConnection',
        'with',
        'without',
        'withOnly',
        'withoutGlobalScope',
        'withoutGlobalScopes',
        'latest',
        'oldest',
        'inRandomOrder',
        'distinct',
        'limit',
        'take',
        'skip',
        'offset',
        'has',
        'orHas',
        'doesntHave',
        'orDoesntHave',
        'whereHas',
        'orWhereHas',
        'whereDoesntHave',
        'orWhereDoesntHave',
        'withWhereHas',
        'withCount',
        'withSum',
        'withAvg',
        'withMin',
        'withMax',
        'withExists',
        'withTrashed',
        'onlyTrashed',
        'withoutTrashed',
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
        ModelIndex $models,
    ): ApplicationSnapshot {
        $statements = $this->parser->parse($source);
        $usages = [];

        foreach (
            $this->finder->findInstanceOf(
                $statements,
                StaticCall::class,
            )
            as $call
        ) {
            $model = $this->modelForStaticCall(
                $call,
                $models,
            );

            if (
                $model === null
                || ! $model->hasKnownTable()
            ) {
                continue;
            }

            foreach (
                $this->analyzeStaticCall(
                    call: $call,
                    model: $model,
                    file: $file,
                )
                as $usage
            ) {
                $usages[] = $usage;
            }
        }

        foreach (
            $this->finder->findInstanceOf(
                $statements,
                MethodCall::class,
            )
            as $call
        ) {
            $model = $this->modelForMethodCall(
                $call,
                $models,
            );

            if (
                $model === null
                || ! $model->hasKnownTable()
            ) {
                continue;
            }

            foreach (
                $this->analyzeMethodCall(
                    call: $call,
                    model: $model,
                    file: $file,
                )
                as $usage
            ) {
                $usages[] = $usage;
            }
        }

        return new ApplicationSnapshot($usages);
    }

    /**
     * @return list<ColumnUsage|TableUsage|WriteUsage>
     */
    private function analyzeStaticCall(
        StaticCall $call,
        ModelDescriptor $model,
        string $file,
    ): array {
        $method = $this->staticMethodName($call);

        if ($method === null) {
            return [];
        }

        if (in_array(
            $method,
            self::DIRECT_STATIC_MODEL_METHODS_TO_IGNORE,
            true,
        )) {
            return [];
        }

        if (
            in_array(
                $method,
                self::INSERT_WRITE_METHODS,
                true,
            )
            || in_array(
                $method,
                self::UPDATE_WRITE_METHODS,
                true,
            )
        ) {
            if ($this->isEmptyInsertNoop(
                $method,
                $call,
            )) {
                return [];
            }

            return [
                $this->analyzeStaticWrite(
                    call: $call,
                    table: $model->table,
                    operation: $method,
                    file: $file,
                ),
            ];
        }

        $columns = $this->staticReferencedColumns(
            $call,
        );

        if ($columns !== []) {
            return array_map(
                static fn (string $column): ColumnUsage =>
                    new ColumnUsage(
                        table: $model->table,
                        column: $column,
                        operation: $method,
                        file: $file,
                        line: $call->getStartLine(),
                    ),
                $columns,
            );
        }

        if (
            in_array(
                $method,
                self::TABLE_TERMINALS,
                true,
            )
        ) {
            return [
                new TableUsage(
                    table: $model->table,
                    operation: $method,
                    file: $file,
                    line: $call->getStartLine(),
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<ColumnUsage|TableUsage|WriteUsage>
     */
    private function analyzeMethodCall(
        MethodCall $call,
        ModelDescriptor $model,
        string $file,
    ): array {
        $method = $this->methodName($call);

        if ($method === null) {
            return [];
        }

        if (
            in_array(
                $method,
                self::INSERT_WRITE_METHODS,
                true,
            )
            || in_array(
                $method,
                self::UPDATE_WRITE_METHODS,
                true,
            )
        ) {
            if ($this->isEmptyInsertNoop(
                $method,
                $call,
            )) {
                return [];
            }

            return [
                $this->analyzeMethodWrite(
                    call: $call,
                    table: $model->table,
                    operation: $method,
                    file: $file,
                ),
            ];
        }

        $columns = $this->methodReferencedColumns(
            $call,
        );

        if ($columns !== []) {
            return array_map(
                static fn (string $column): ColumnUsage =>
                    new ColumnUsage(
                        table: $model->table,
                        column: $column,
                        operation: $method,
                        file: $file,
                        line: $call->getStartLine(),
                    ),
                $columns,
            );
        }

        if (
            ! in_array(
                $method,
                self::TABLE_TERMINALS,
                true,
            )
        ) {
            return [];
        }

        if ($this->hasColumnUsageInChain($call)) {
            return [];
        }

        return [
            new TableUsage(
                table: $model->table,
                operation: $method,
                file: $file,
                line: $call->getStartLine(),
            ),
        ];
    }

    private function isEmptyInsertNoop(
        string $operation,
        StaticCall|MethodCall $call,
    ): bool {
        if (! in_array(
            $operation,
            ['insert', 'insertOrIgnore'],
            true,
        )) {
            return false;
        }

        $payload = $call->args[0]->value ?? null;

        return $payload instanceof Array_
            && $payload->items === [];
    }

    private function analyzeStaticWrite(
        StaticCall $call,
        ?string $table,
        string $operation,
        string $file,
    ): WriteUsage {
        if (in_array(
            $operation,
            self::MODEL_CREATE_METHODS,
            true,
        )) {
            return new WriteUsage(
                table: $table,
                operation: $operation,
                columns: null,
                reason: 'eloquent_model_create_semantics',
                file: $file,
                line: $call->getStartLine(),
            );
        }

        $payload = $call->args[0]->value ?? null;

        [$columns, $reason] =
            $this->writePayload($payload);

        return new WriteUsage(
            table: $table,
            operation: $operation,
            columns: $columns,
            reason: $reason,
            file: $file,
            line: $call->getStartLine(),
        );
    }

    private function analyzeMethodWrite(
        MethodCall $call,
        ?string $table,
        string $operation,
        string $file,
    ): WriteUsage {
        if (in_array(
            $operation,
            self::MODEL_CREATE_METHODS,
            true,
        )) {
            return new WriteUsage(
                table: $table,
                operation: $operation,
                columns: null,
                reason: 'eloquent_model_create_semantics',
                file: $file,
                line: $call->getStartLine(),
            );
        }

        $payload = $call->args[0]->value ?? null;

        [$columns, $reason] =
            $this->writePayload($payload);

        return new WriteUsage(
            table: $table,
            operation: $operation,
            columns: $columns,
            reason: $reason,
            file: $file,
            line: $call->getStartLine(),
        );
    }

    /**
     * @return array{list<string>|null, ?string}
     */
    private function writePayload(
        mixed $payload,
    ): array {
        if (! $payload instanceof Array_) {
            return [null, 'dynamic_payload'];
        }

        $columns = $this->literalWriteColumns(
            $payload,
        );

        if ($columns === null) {
            return [null, 'dynamic_column_key'];
        }

        return [$columns, null];
    }

    /**
     * @return list<string>
     */
    private function staticReferencedColumns(
        StaticCall $call,
    ): array {
        $method = $this->staticMethodName($call);

        if ($method === null) {
            return [];
        }

        if (
            in_array(
                $method,
                self::FIRST_ARGUMENT_COLUMN_METHODS,
                true,
            )
        ) {
            return $this->staticColumnsAt(
                $call,
                0,
            );
        }

        if (
            in_array(
                $method,
                self::MULTI_COLUMN_METHODS,
                true,
            )
        ) {
            return $this->staticColumnsFromArguments(
                $call,
            );
        }

        if ($method === 'whereColumn') {
            $secondPosition = isset($call->args[2])
                ? 2
                : 1;

            return array_values(array_unique([
                ...$this->staticColumnsAt(
                    $call,
                    0,
                ),
                ...$this->staticColumnsAt(
                    $call,
                    $secondPosition,
                ),
            ]));
        }

        if ($method === 'pluck') {
            return array_values(array_unique([
                ...$this->staticColumnsAt($call, 0),
                ...$this->staticColumnsAt($call, 1),
            ]));
        }

        if ($method === 'value') {
            return $this->staticColumnsAt($call, 0);
        }

        if (
            in_array(
                $method,
                ['count', 'sum', 'avg', 'average', 'min', 'max'],
                true,
            )
        ) {
            return array_values(array_filter(
                $this->staticColumnsAt($call, 0),
                static fn (string $column): bool =>
                    $column !== '*',
            ));
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function methodReferencedColumns(
        MethodCall $call,
    ): array {
        $method = $this->methodName($call);

        if ($method === null) {
            return [];
        }

        if (
            in_array(
                $method,
                self::FIRST_ARGUMENT_COLUMN_METHODS,
                true,
            )
        ) {
            return $this->methodColumnsAt(
                $call,
                0,
            );
        }

        if (
            in_array(
                $method,
                self::MULTI_COLUMN_METHODS,
                true,
            )
        ) {
            return $this->methodColumnsFromArguments(
                $call,
            );
        }

        if ($method === 'whereColumn') {
            $secondPosition = isset($call->args[2])
                ? 2
                : 1;

            return array_values(array_unique([
                ...$this->methodColumnsAt(
                    $call,
                    0,
                ),
                ...$this->methodColumnsAt(
                    $call,
                    $secondPosition,
                ),
            ]));
        }

        if ($method === 'pluck') {
            return array_values(array_unique([
                ...$this->methodColumnsAt($call, 0),
                ...$this->methodColumnsAt($call, 1),
            ]));
        }

        if ($method === 'value') {
            return $this->methodColumnsAt($call, 0);
        }

        if (
            in_array(
                $method,
                ['get', 'first', 'firstOrFail', 'sole'],
                true,
            )
        ) {
            return $this->methodColumnsFromArguments(
                $call,
            );
        }

        if (
            in_array(
                $method,
                ['count', 'sum', 'avg', 'average', 'min', 'max'],
                true,
            )
        ) {
            return array_values(array_filter(
                $this->methodColumnsAt($call, 0),
                static fn (string $column): bool =>
                    $column !== '*',
            ));
        }

        return [];
    }

    private function hasColumnUsageInChain(
        MethodCall $call,
    ): bool {
        $current = $call->var;

        while ($current instanceof MethodCall) {
            if (
                $this->methodReferencedColumns(
                    $current,
                ) !== []
            ) {
                return true;
            }

            $current = $current->var;
        }

        if ($current instanceof StaticCall) {
            return $this->staticReferencedColumns(
                $current,
            ) !== [];
        }

        return false;
    }

    private function modelForStaticCall(
        StaticCall $call,
        ModelIndex $models,
    ): ?ModelDescriptor {
        if (! $call->class instanceof Name) {
            return null;
        }

        return $models->find(
            $call->class->toString(),
        );
    }

    private function modelForMethodCall(
        MethodCall $call,
        ModelIndex $models,
    ): ?ModelDescriptor {
        $current = $call->var;

        while ($current instanceof MethodCall) {
            $current = $current->var;
        }

        if (! $current instanceof StaticCall) {
            return null;
        }

        if (! $this->isSupportedQueryRootStaticCall(
            $current,
        )) {
            return null;
        }

        return $this->modelForStaticCall(
            $current,
            $models,
        );
    }

    private function isSupportedQueryRootStaticCall(
        StaticCall $call,
    ): bool {
        $method = $this->staticMethodName($call);

        if ($method === null) {
            return false;
        }

        return in_array(
            $method,
            self::QUERY_ROOT_STATIC_METHODS,
            true,
        )
            || in_array(
                $method,
                self::FIRST_ARGUMENT_COLUMN_METHODS,
                true,
            )
            || in_array(
                $method,
                self::MULTI_COLUMN_METHODS,
                true,
            )
            || $method === 'whereColumn';
    }

    private function staticMethodName(
        StaticCall $call,
    ): ?string {
        if (! $call->name instanceof Identifier) {
            return null;
        }

        return $call->name->toString();
    }

    private function methodName(
        MethodCall $call,
    ): ?string {
        if (! $call->name instanceof Identifier) {
            return null;
        }

        return $call->name->toString();
    }

    /**
     * @return list<string>
     */
    private function staticColumnsAt(
        StaticCall $call,
        int $position,
    ): array {
        $value = $call->args[$position]->value
            ?? null;

        return $this->literalColumnsFromValue(
            $value,
        );
    }

    /**
     * @return list<string>
     */
    private function methodColumnsAt(
        MethodCall $call,
        int $position,
    ): array {
        $value = $call->args[$position]->value
            ?? null;

        return $this->literalColumnsFromValue(
            $value,
        );
    }

    /**
     * @return list<string>
     */
    private function staticColumnsFromArguments(
        StaticCall $call,
    ): array {
        $columns = [];

        foreach ($call->args as $argument) {
            foreach (
                $this->literalColumnsFromValue(
                    $argument->value,
                )
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
    private function methodColumnsFromArguments(
        MethodCall $call,
    ): array {
        $columns = [];

        foreach ($call->args as $argument) {
            foreach (
                $this->literalColumnsFromValue(
                    $argument->value,
                )
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
}
