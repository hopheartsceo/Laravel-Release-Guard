<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Analysis\Migrations;

use Hopheartsceo\ReleaseGuard\Analysis\Ast\PhpAstParserService;
use Hopheartsceo\ReleaseGuard\Domain\Schema\DroppedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\SchemaDelta;
use Hopheartsceo\ReleaseGuard\Domain\Schema\UnanalyzableMigrationOperation;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;

final class MigrationAnalyzer
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
    ): SchemaDelta {
        $statements = $this->parser->parse($source);
        $changes = [];

        $schemaCalls = $this->finder->findInstanceOf(
            $statements,
            StaticCall::class,
        );

        foreach ($schemaCalls as $schemaCall) {
            if (! $this->isSchemaTableCall($schemaCall)) {
                continue;
            }

            $table = $this->literalStringArgument($schemaCall, 0);
            $closure = $schemaCall->args[1]->value ?? null;

            if (! $closure instanceof Closure) {
                continue;
            }

            $blueprintVariable = $this->blueprintVariable($closure);

            if ($blueprintVariable === null) {
                continue;
            }

            $methodCalls = $this->finder->findInstanceOf(
                $closure->stmts ?? [],
                MethodCall::class,
            );

            foreach ($methodCalls as $methodCall) {
                if (! $this->isMethodOnVariable(
                    $methodCall,
                    $blueprintVariable,
                    'dropColumn',
                )) {
                    continue;
                }

                $column = $this->literalStringArgument($methodCall, 0);

                if ($table === null || $column === null) {
                    $changes[] = new UnanalyzableMigrationOperation(
                        operation: 'dropColumn',
                        table: $table,
                        column: $column,
                        reason: $this->unknownReason($table, $column),
                        file: $file,
                        line: $methodCall->getStartLine(),
                    );

                    continue;
                }

                $changes[] = new DroppedColumn(
                    table: $table,
                    column: $column,
                    file: $file,
                    line: $methodCall->getStartLine(),
                );
            }
        }

        return new SchemaDelta($changes);
    }

    private function isSchemaTableCall(StaticCall $call): bool
    {
        if (
            ! $call->class instanceof Name
            || ! $call->name instanceof Identifier
        ) {
            return false;
        }

        return $call->class->toString() === 'Illuminate\Support\Facades\Schema'
            && $call->name->toString() === 'table';
    }

    private function blueprintVariable(Closure $closure): ?string
    {
        $parameter = $closure->params[0] ?? null;

        if (
            $parameter === null
            || ! $parameter->var instanceof Variable
            || ! is_string($parameter->var->name)
        ) {
            return null;
        }

        return $parameter->var->name;
    }

    private function isMethodOnVariable(
        MethodCall $call,
        string $variable,
        string $method,
    ): bool {
        return $call->var instanceof Variable
            && $call->var->name === $variable
            && $call->name instanceof Identifier
            && $call->name->toString() === $method;
    }

    private function literalStringArgument(
        StaticCall|MethodCall $call,
        int $position,
    ): ?string {
        $argument = $call->args[$position]->value ?? null;

        if (! $argument instanceof String_) {
            return null;
        }

        return $argument->value;
    }

    private function unknownReason(
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
