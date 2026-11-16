<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\SentryReleasePass;
use App\Service\BuildIdentity;
use Sentry\Options;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;

final class SentryReleasePassTest extends KernelTestCase
{
    public function test_the_release_is_the_build_version(): void
    {
        $projectDir = sys_get_temp_dir().'/sentry-release-pass-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($projectDir.'/var/build-version', "a24bdfe00\n");

        try {
            self::assertSame('a24bdfe00', $this->compiledOptions($projectDir)->getRelease());
        } finally {
            $filesystem->remove($projectDir);
        }
    }

    public function test_an_instance_with_no_build_file_sends_no_release(): void
    {
        $projectDir = sys_get_temp_dir().'/sentry-release-pass-'.bin2hex(random_bytes(4));

        self::assertNull($this->compiledOptions($projectDir)->getRelease());
    }

    public function test_the_kernel_wires_the_release_from_the_build_identity(): void
    {
        $container = self::getContainer();
        $options = $container->get('sentry.client.options');
        self::assertInstanceOf(Options::class, $options);

        self::assertSame($container->get(BuildIdentity::class)->version, $options->getRelease());
    }

    private function compiledOptions(string $projectDir): Options
    {
        $container = new ContainerBuilder();
        $container->register(BuildIdentity::class, BuildIdentity::class)->setArgument(0, $projectDir)->setPublic(true);
        $container->register('sentry.client.options', Options::class)
            ->setArgument(0, ['release' => 'composer-fallback'])
            ->setPublic(true);

        $container->addCompilerPass(new SentryReleasePass());
        $container->compile();

        $options = $container->get('sentry.client.options');
        self::assertInstanceOf(Options::class, $options);

        return $options;
    }
}
