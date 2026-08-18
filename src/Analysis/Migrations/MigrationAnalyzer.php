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
use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;

final class MigrationAnalyzer
{
    private readonly PhpAstParserService $parser;

    private readonly NodeFinder $finder;

    private readonly BlueprintAddedColumnAnalyzer $addedColumnAnalyzer;

    public function __construct(
        ?PhpAstParserService $parser = null,
        ?NodeFinder $finder = null,
        ?BlueprintAddedColumnAnalyzer $addedColumnAnalyzer = null,
    ) {
        $this->parser = $parser ?? new PhpAstParserService();
        $this->finder = $finder ?? new NodeFinder();
        $this->addedColumnAnalyzer = $addedColumnAnalyzer
            ?? new BlueprintAddedColumnAnalyzer();
    }

    public function analyze(
        string $source,
        string $file,
    ): SchemaDelta {
        $statements = $this->parser->parse($source);
        $analysisStatements = $this->analysisStatements($statements);
        $changes = [];

        $schemaCalls = $this->finder->findInstanceOf(
            $analysisStatements,
            StaticCall::class,
        );

        foreach ($schemaCalls as $schemaCall) {
            if ($this->isDbCall(
                $schemaCall,
                'statement',
            )) {
                $changes[] = new UnanalyzableMigrationOperation(
                    operation: 'statement',
                    table: null,
                    column: null,
                    reason: 'raw_sql',
                    file: $file,
                    line: $schemaCall->getStartLine(),
                );

                continue;
            }

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
     * Real Laravel migration files are analyzed from up() only.
     *
     * Plain snippets without a Migration class remain fully analyzable,
     * which is useful for focused analysis and unit tests.
     *
     * @param array<Node\Stmt> $statements
     * @return array<Node\Stmt>
     */
    private function analysisStatements(array $statements): array
    {
        $classes = $this->finder->findInstanceOf(
            $statements,
            Class_::class,
        );

        $migrationClasses = array_values(array_filter(
            $classes,
            fn (Class_ $class): bool => $this->isMigrationClass($class),
        ));

        if ($migrationClasses === []) {
            return $statements;
        }

        $upStatements = [];

        foreach ($migrationClasses as $migrationClass) {
            foreach ($migrationClass->getMethods() as $method) {
                if ($method->name->toString() !== 'up') {
                    continue;
                }

                foreach ($method->stmts ?? [] as $statement) {
                    $upStatements[] = $statement;
                }
            }
        }

        return $upStatements;
    }

    private function isMigrationClass(Class_ $class): bool
    {
        return $class->extends instanceof Name
            && $class->extends->toString()
                === 'Illuminate\Database\Migrations\Migration';
    }

    /**
     * @return list<
     *     \Hopheartsceo\ReleaseGuard\Domain\Schema\AddedColumn
     *     |DroppedColumn
     *     |RenamedColumn
     *     |UnanalyzableMigrationOperation
     * >
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

        foreach ($this->addedColumnAnalyzer->analyze(
            statements: $closure->stmts ?? [],
            blueprintVariable: $blueprintVariable,
            table: $table,
            file: $file,
        ) as $addedColumn) {
            $changes[] = $addedColumn;
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

    private function isDbCall(
        StaticCall $call,
        string $method,
    ): bool {
        if (
            ! $call->class instanceof Name
            || ! $call->name instanceof Identifier
        ) {
            return false;
        }

        return $call->class->toString()
                === 'Illuminate\\Support\\Facades\\DB'
            && $call->name->toString() === $method;
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
