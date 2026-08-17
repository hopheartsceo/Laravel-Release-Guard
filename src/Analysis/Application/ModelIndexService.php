<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Analysis\Application;

use Hopheartsceo\ReleaseGuard\Analysis\Ast\PhpAstParserService;
use Hopheartsceo\ReleaseGuard\Domain\Application\ModelDescriptor;
use Hopheartsceo\ReleaseGuard\Domain\Application\ModelIndex;
use Hopheartsceo\ReleaseGuard\Domain\Source\SourceFile;
use Illuminate\Support\Str;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;

final class ModelIndexService
{
    private const ELOQUENT_MODEL =
        'Illuminate\Database\Eloquent\Model';

    private readonly PhpAstParserService $parser;

    private readonly NodeFinder $finder;

    public function __construct(
        ?PhpAstParserService $parser = null,
        ?NodeFinder $finder = null,
    ) {
        $this->parser = $parser ?? new PhpAstParserService();
        $this->finder = $finder ?? new NodeFinder();
    }

    /**
     * @param list<SourceFile> $files
     */
    public function build(array $files): ModelIndex
    {
        $candidates = [];

        foreach ($files as $file) {
            $statements = $this->parser->parse(
                $file->contents,
            );

            $classes = $this->finder->findInstanceOf(
                $statements,
                Class_::class,
            );

            foreach ($classes as $class) {
                if (
                    $class->name === null
                    || $class->extends === null
                ) {
                    continue;
                }

                $className = $this->className($class);

                if ($className === null) {
                    continue;
                }

                [$tableState, $table] =
                    $this->tableProperty($class);

                $candidates[$className] = [
                    'className' => $className,
                    'shortName' => $class->name->toString(),
                    'parent' => $class->extends->toString(),
                    'tableState' => $tableState,
                    'table' => $table,
                    'customGetTable' =>
                        $this->hasCustomGetTable($class),
                    'file' => $file->path,
                    'line' => $class->getStartLine(),
                ];
            }
        }

        $models = [];

        foreach (array_keys($candidates) as $className) {
            if (
                ! $this->isEloquentModel(
                    $className,
                    $candidates,
                )
            ) {
                continue;
            }

            $resolved = $this->resolveTable(
                $className,
                $candidates,
            );

            $candidate = $candidates[$className];

            $models[] = new ModelDescriptor(
                className: $className,
                table: $resolved['table'],
                reason: $resolved['reason'],
                file: $candidate['file'],
                line: $candidate['line'],
            );
        }

        return new ModelIndex($models);
    }

    private function className(
        Class_ $class,
    ): ?string {
        $namespacedName = $class->namespacedName;

        if ($namespacedName instanceof Name) {
            return $namespacedName->toString();
        }

        return $class->name?->toString();
    }

    /**
     * @return array{string, ?string}
     */
    private function tableProperty(
        Class_ $class,
    ): array {
        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Property) {
                continue;
            }

            foreach ($statement->props as $property) {
                if (
                    $property->name->toString()
                    !== 'table'
                ) {
                    continue;
                }

                $default = $property->default;

                if ($default instanceof String_) {
                    return ['explicit', $default->value];
                }

                if (
                    $default === null
                    || (
                        $default instanceof ConstFetch
                        && strtolower(
                            $default->name->toString(),
                        ) === 'null'
                    )
                ) {
                    return ['convention', null];
                }

                return ['unknown', null];
            }
        }

        return ['convention', null];
    }

    private function hasCustomGetTable(
        Class_ $class,
    ): bool {
        foreach ($class->stmts as $statement) {
            if (
                $statement instanceof ClassMethod
                && $statement->name->toString()
                    === 'getTable'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array<string, mixed>> $candidates
     */
    private function isEloquentModel(
        string $className,
        array $candidates,
        array $visited = [],
    ): bool {
        if (isset($visited[$className])) {
            return false;
        }

        $visited[$className] = true;

        $candidate = $candidates[$className] ?? null;

        if ($candidate === null) {
            return false;
        }

        $parent = $candidate['parent'];

        if ($parent === self::ELOQUENT_MODEL) {
            return true;
        }

        if (! isset($candidates[$parent])) {
            return false;
        }

        return $this->isEloquentModel(
            $parent,
            $candidates,
            $visited,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $candidates
     * @return array{
     *     table: ?string,
     *     reason: ?string,
     *     mode: string
     * }
     */
    private function resolveTable(
        string $className,
        array $candidates,
        array $visited = [],
    ): array {
        if (isset($visited[$className])) {
            return [
                'table' => null,
                'reason' => 'model_inheritance_cycle',
                'mode' => 'unknown',
            ];
        }

        $visited[$className] = true;
        $candidate = $candidates[$className];

        if ($candidate['customGetTable']) {
            return [
                'table' => null,
                'reason' => 'custom_get_table',
                'mode' => 'unknown',
            ];
        }

        $parentResolution = null;
        $parent = $candidate['parent'];

        if (
            $parent !== self::ELOQUENT_MODEL
            && isset($candidates[$parent])
            && $this->isEloquentModel(
                $parent,
                $candidates,
            )
        ) {
            $parentResolution = $this->resolveTable(
                $parent,
                $candidates,
                $visited,
            );

            if (
                $parentResolution['reason']
                === 'custom_get_table'
            ) {
                return [
                    'table' => null,
                    'reason' => 'custom_get_table',
                    'mode' => 'unknown',
                ];
            }
        }

        if ($candidate['tableState'] === 'explicit') {
            return [
                'table' => $candidate['table'],
                'reason' => null,
                'mode' => 'explicit',
            ];
        }

        if ($candidate['tableState'] === 'unknown') {
            return [
                'table' => null,
                'reason' => 'dynamic_table_property',
                'mode' => 'unknown',
            ];
        }

        if ($parentResolution !== null) {
            if (
                in_array(
                    $parentResolution['mode'],
                    ['explicit', 'inherited'],
                    true,
                )
            ) {
                return [
                    'table' => $parentResolution['table'],
                    'reason' => null,
                    'mode' => 'inherited',
                ];
            }

            if (
                $parentResolution['mode'] === 'unknown'
            ) {
                return $parentResolution;
            }
        }

        return [
            'table' => Str::snake(
                Str::pluralStudly(
                    $candidate['shortName'],
                ),
            ),
            'reason' => null,
            'mode' => 'convention',
        ];
    }
}
