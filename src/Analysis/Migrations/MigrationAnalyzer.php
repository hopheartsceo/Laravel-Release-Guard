<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Analysis\Migrations;

use Hopheartsceo\ReleaseGuard\Analysis\Ast\PhpAstParserService;
use Hopheartsceo\ReleaseGuard\Domain\Schema\DroppedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\DroppedTable;
use Hopheartsceo\ReleaseGuard\Domain\Schema\RenamedColumn;
use Hopheartsceo\ReleaseGuard\Domain\Schema\RenamedTable;
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
            if ($this->isSchemaCall($schemaCall, 'table')) {
                foreach ($this->analyzeSchemaTableCall($schemaCall, $file) as $change) {
                    $changes[] = $change;
                }

                continue;
            }

            if (
                $this->isSchemaCall($schemaCall, 'drop')
                || $this->isSchemaCall($schemaCall, 'dropIfExists')
            ) {
                $operation = $this->isSchemaCall($schemaCall, 'dropIfExists')
                    ? 'dropIfExists'
                    : 'drop';

                $table = $this->literalStringArgument($schemaCall, 0);

                if ($table === null) {
                    $changes[] = new UnanalyzableMigrationOperation(
                        operation: $operation,
                        table: null,
                        column: null,
                        reason: 'dynamic_table',
                        file: $file,
                        line: $schemaCall->getStartLine(),
                    );

                    continue;
                }

                $changes[] = new DroppedTable(
                    table: $table,
                    ifExists: $operation === 'dropIfExists',
                    file: $file,
                    line: $schemaCall->getStartLine(),
                );

                continue;
            }

            if ($this->isSchemaCall($schemaCall, 'rename')) {
                $from = $this->literalStringArgument($schemaCall, 0);
                $to = $this->literalStringArgument($schemaCall, 1);

                if ($from === null || $to === null) {
                    $changes[] = new UnanalyzableMigrationOperation(
                        operation: 'rename',
                        table: $from,
                        column: null,
                        reason: $this->renameTableUnknownReason($from, $to),
                        file: $file,
                        line: $schemaCall->getStartLine(),
                    );

                    continue;
                }

                $changes[] = new RenamedTable(
                    from: $from,
                    to: $to,
                    file: $file,
                    line: $schemaCall->getStartLine(),
                );
            }
        }

        return new SchemaDelta($changes);
    }

    /**
     * @return list<DroppedColumn|RenamedColumn|UnanalyzableMigrationOperation>
     */
    private function analyzeSchemaTableCall(
        StaticCall $schemaCall,
        string $file,
    ): array {
        $changes = [];

        $table = $this->literalStringArgument($schemaCall, 0);
        $closure = $schemaCall->args[1]->value ?? null;

        if (! $closure instanceof Closure) {
            return [];
        }

        $blueprintVariable = $this->blueprintVariable($closure);

        if ($blueprintVariable === null) {
            return [];
        }

        $methodCalls = $this->finder->findInstanceOf(
            $closure->stmts ?? [],
            MethodCall::class,
        );

        foreach ($methodCalls as $methodCall) {
            if ($this->isMethodOnVariable(
                $methodCall,
                $blueprintVariable,
                'dropColumn',
            )) {
                $column = $this->literalStringArgument($methodCall, 0);

                if ($table === null || $column === null) {
                    $changes[] = new UnanalyzableMigrationOperation(
                        operation: 'dropColumn',
                        table: $table,
                        column: $column,
                        reason: $this->dropColumnUnknownReason($table, $column),
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

                continue;
            }

            if ($this->isMethodOnVariable(
                $methodCall,
                $blueprintVariable,
                'renameColumn',
            )) {
                $from = $this->literalStringArgument($methodCall, 0);
                $to = $this->literalStringArgument($methodCall, 1);

                if (
                    $table === null
                    || $from === null
                    || $to === null
                ) {
                    $changes[] = new UnanalyzableMigrationOperation(
                        operation: 'renameColumn',
                        table: $table,
                        column: $from,
                        reason: $this->renameColumnUnknownReason(
                            $table,
                            $from,
                            $to,
                        ),
                        file: $file,
                        line: $methodCall->getStartLine(),
                    );

                    continue;
                }

                $changes[] = new RenamedColumn(
                    table: $table,
                    from: $from,
                    to: $to,
                    file: $file,
                    line: $methodCall->getStartLine(),
                );
            }
        }

        return $changes;
    }

    private function isSchemaCall(
        StaticCall $call,
        string $method,
    ): bool {
        if (
            ! $call->class instanceof Name
            || ! $call->name instanceof Identifier
        ) {
            return false;
        }

        return $call->class->toString() === 'Illuminate\Support\Facades\Schema'
            && $call->name->toString() === $method;
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

    private function dropColumnUnknownReason(
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

    private function renameColumnUnknownReason(
        ?string $table,
        ?string $from,
        ?string $to,
    ): string {
        if ($table === null) {
            return 'dynamic_table';
        }

        if ($from === null && $to === null) {
            return 'dynamic_source_and_target_column';
        }

        if ($from === null) {
            return 'dynamic_source_column';
        }

        return 'dynamic_target_column';
    }

    private function renameTableUnknownReason(
        ?string $from,
        ?string $to,
    ): string {
        if ($from === null && $to === null) {
            return 'dynamic_source_and_target_table';
        }

        if ($from === null) {
            return 'dynamic_source_table';
        }

        return 'dynamic_target_table';
    }
}
