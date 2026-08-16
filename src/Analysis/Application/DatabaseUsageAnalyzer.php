<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Analysis\Application;

use Hopheartsceo\ReleaseGuard\Analysis\Ast\PhpAstParserService;
use Hopheartsceo\ReleaseGuard\Domain\Application\ApplicationSnapshot;
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
            if (! $this->isInsertCall($methodCall)) {
                continue;
            }

            $tableCall = $methodCall->var;

            if (! $tableCall instanceof StaticCall) {
                continue;
            }

            $table = $this->literalStringArgument($tableCall, 0);
            $payload = $methodCall->args[0]->value ?? null;

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

            $usages[] = new WriteUsage(
                table: $table,
                operation: 'insert',
                columns: $columns,
                reason: $reason,
                file: $file,
                line: $methodCall->getStartLine(),
            );
        }

        return new ApplicationSnapshot($usages);
    }

    private function isInsertCall(MethodCall $call): bool
    {
        if (
            ! $call->name instanceof Identifier
            || $call->name->toString() !== 'insert'
            || ! $call->var instanceof StaticCall
        ) {
            return false;
        }

        return $this->isDbTableCall($call->var);
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
