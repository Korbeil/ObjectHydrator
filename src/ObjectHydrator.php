<?php

declare(strict_types=1);

namespace EventSauce\ObjectHydrator;

use Throwable;
use function array_key_exists;
use function count;
use function current;
use function is_array;

/**
 * @template T
 * @template I
 */
class ObjectHydrator
{
    private ?DefinitionProvider $definitionProvider;

    /**
     * @var array<class-string<I>, I>
     */
    private $instances;

    public function __construct(
        ?DefinitionProvider $definitionProvider = null,
    ) {
        $this->definitionProvider = $definitionProvider ?: new ReflectionDefinitionProvider();
    }

    /**
     * @param class-string<T> $className
     *
     * @return T
     */
    public function hydrateObject(string $className, array $payload): object
    {
        try {
            $classDefinition = $this->definitionProvider->provideDefinition($className);

            $properties = [];

            foreach ($classDefinition->propertyDefinitions as $definition) {
                $value = [];

                foreach ($definition->keys as $from => $to) {
                    if (array_key_exists($from, $payload)) {
                        $value[$to] = $payload[$from];
                    }
                }

                if ($value === []) {
                    continue;
                }

                if (count($definition->keys) === 1) {
                    $value = current($value);
                }

                $property = $definition->property;

                foreach ($definition->propertyCasters as $index => [$caster, $options]) {
                    $key = "$className-$index-$caster";
                    /** @var PropertyCaster $propertyCaster */
                    $propertyCaster = $this->instances[$key] ??= new $caster(...$options);
                    $value = $propertyCaster->cast($value, $this);
                }

                $typeName = $definition->concreteTypeName;

                if ($definition->isEnum) {
                    $value = $typeName::from($value);
                } elseif ($definition->canBeHydrated && is_array($value)) {
                    $value = $this->hydrateObject($typeName, $value);
                }

                $properties[$property] = $value;
            }

            return match ($classDefinition->constructionStyle) {
                'static' => ($classDefinition->constructor)(...$properties),
                'new' => new ($classDefinition->constructor)(...$properties),
            };
        } catch (Throwable $exception) {
            throw UnableToHydrateObject::dueToError($className, $exception);
        }
    }
}
