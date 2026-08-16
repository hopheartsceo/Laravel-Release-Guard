<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Analysis\Application;

use Hopheartsceo\ReleaseGuard\Analysis\Ast\PhpAstParserService;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
use Hopheartsceo\ReleaseGuard\Domain\Application\ColumnUsage;
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

        foreach ($methodCalls as $methodCall) {
            if ($this->isInsertCall($methodCall)) {
                $usages[] = $this->analyzeInsert(
                    call: $methodCall,
                    file: $file,
                );

                continue;
            }

            if ($this->isNamedMethod($methodCall, 'where')) {
                $usage = $this->analyzeWhere(
                    call: $methodCall,
                    file: $file,
                );

                if ($usage !== null) {
                    $usages[] = $usage;
                }

                continue;
            }

            if ($this->isNamedMethod($methodCall, 'select')) {
                foreach ($this->analyzeSelect(
                    call: $methodCall,
                    file: $file,
                ) as $usage) {
                    $usages[] = $usage;
                }
            }
        }

        return new ApplicationSnapshot($usages);
    }

    private function analyzeInsert(
        MethodCall $call,
        string $file,
    ): WriteUsage {
        $tableCall = $call->var;

        $table = $tableCall instanceof StaticCall
            ? $this->literalStringArgument($tableCall, 0)
            : null;

        $payload = $call->args[0]->value ?? null;

        $columns = null;
        $reason = null;

        if ($payload instanceof Array_) {
            $columns = $this->literalArrayKeys($payload);

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
            operation: 'insert',
            columns: $columns,
            reason: $reason,
            file: $file,
            line: $call->getStartLine(),
        );
    }

    private function analyzeWhere(
        MethodCall $call,
        string $file,
    ): ?ColumnUsage {
        $table = $this->queryBuilderTable($call);
        $column = $this->literalMethodStringArgument($call, 0);

        if ($table === null || $column === null) {
            return null;
        }

        return new ColumnUsage(
            table: $table,
            column: $column,
            operation: 'where',
            file: $file,
            line: $call->getStartLine(),
        );
    }

    /**
     * @return list<ColumnUsage>
     */
    private function analyzeSelect(
        MethodCall $call,
        string $file,
    ): array {
        $table = $this->queryBuilderTable($call);

        if ($table === null) {
            return [];
        }

        $usages = [];

        foreach ($call->args as $argument) {
            if (! $argument->value instanceof String_) {
                continue;
            }

            $usages[] = new ColumnUsage(
                table: $table,
                column: $argument->value->value,
                operation: 'select',
                file: $file,
                line: $call->getStartLine(),
            );
        }

        return $usages;
    }

    private function queryBuilderTable(MethodCall $call): ?string
    {
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

        return $this->literalStringArgument($current, 0);
    }

    private function isInsertCall(MethodCall $call): bool
    {
        if (
            ! $this->isNamedMethod($call, 'insert')
            || ! $call->var instanceof StaticCall
        ) {
            return false;
        }

        return $this->isDbTableCall($call->var);
    }

    private function isNamedMethod(
        MethodCall $call,
        string $method,
    ): bool {
        return $call->name instanceof Identifier
            && $call->name->toString() === $method;
    }

    private function isDbTableCall(StaticCall $call): bool
    {
        if (
            ! $call->class instanceof Name
            || ! $call->name instanceof Identifier
        ) {
            return false;
        }

        return $call->class->toString() === 'Illuminate\Support\Facades\DB'
            && $call->name->toString() === 'table';
    }

    private function literalStringArgument(
        StaticCall $call,
        int $position,
    ): ?string {
        $argument = $call->args[$position]->value ?? null;

        if (! $argument instanceof String_) {
            return null;
        }

        return $argument->value;
    }

    private function literalMethodStringArgument(
        MethodCall $call,
        int $position,
    ): ?string {
        $argument = $call->args[$position]->value ?? null;

        if (! $argument instanceof String_) {
            return null;
        }

        return $argument->value;
    }

    /**
     * @return list<string>|null
     */
    private function literalArrayKeys(Array_ $array): ?array
    {
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

    private function withDynamicTable(?string $reason): string
    {
        return match ($reason) {
            'dynamic_payload' => 'dynamic_table_and_payload',
            'dynamic_column_key' => 'dynamic_table_and_column_key',
            default => 'dynamic_table',
        };
    }
}
