<?php

declare(strict_types=1);

namespace App\Routing;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Writes the class that each controller service ID resolves to, after the
 * decorators are applied. A route can name a controller by service ID, and a
 * decorator then runs in place of the service it decorates. The map lets
 * ControllerAttributeProbe read the class a request runs, without creating the
 * controller and its dependencies.
 */
final class ControllerServiceClassPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ControllerAttributeProbe::class)) {
            return;
        }

        $ids = array_keys($container->findTaggedServiceIds('controller.service_arguments'));

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isPublic()) {
                $ids[] = $id;
            }
        }

        foreach ($container->getAliases() as $id => $alias) {
            if ($alias->isPublic()) {
                $ids[] = $id;
            }
        }

        $classes = [];
        foreach (array_unique($ids) as $id) {
            $id = (string) $id;
            if (class_exists($id) || !$container->has($id)) {
                continue;
            }

            $class = $container->getParameterBag()->resolveValue($container->findDefinition($id)->getClass());
            if (\is_string($class) && '' !== $class) {
                $classes[$id] = $class;
            }
        }

        $container->getDefinition(ControllerAttributeProbe::class)->replaceArgument(1, $classes);
    }
}
