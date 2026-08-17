<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Domain\Application;

final class ModelIndex
{
    /**
     * @var array<string, ModelDescriptor>
     */
    private array $models = [];

    /**
     * @param list<ModelDescriptor> $models
     */
    public function __construct(array $models)
    {
        foreach ($models as $model) {
            $this->models[$model->className] = $model;
        }
    }

    public function find(string $className): ?ModelDescriptor
    {
        return $this->models[$className] ?? null;
    }

    /**
     * @return list<ModelDescriptor>
     */
    public function models(): array
    {
        return array_values($this->models);
    }
}
