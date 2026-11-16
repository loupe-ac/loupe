<?php

declare(strict_types=1);

namespace App\Observability;

use App\Service\BuildIdentity;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\ExpressionLanguage\Expression;

/**
 * Names the Sentry release after the build version, the value that /healthz
 * and the browser SDK report. Without it the bundle falls back to the root
 * Composer version, which is the same for every build.
 */
final class SentryReleasePass implements CompilerPassInterface
{
    private const string OPTIONS_ID = 'sentry.client.options';

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::OPTIONS_ID)) {
            return;
        }

        $container->getDefinition(self::OPTIONS_ID)->addMethodCall(
            'setRelease',
            [new Expression(\sprintf("service('%s').version", addslashes(BuildIdentity::class)))],
        );
    }
}
