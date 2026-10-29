<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Kernel;
use Mcp\Server\Session\Psr16SessionStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;

final class McpSessionStoreWiringTest extends TestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/mcp-wiring-'.bin2hex(random_bytes(4));
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function test_production_keeps_mcp_sessions_in_the_database_cache(): void
    {
        $kernel = $this->prodKernel();
        $kernel->boot();
        $container = $kernel->getContainer();

        $store = $container->get('mcp.session.store');
        self::assertInstanceOf(Psr16SessionStore::class, $store);
        self::assertSame(86400, $this->property($store, 'ttl'));

        $pool = $this->property($store, 'cache');
        self::assertInstanceOf(Psr16Cache::class, $pool);
        self::assertInstanceOf(DoctrineDbalAdapter::class, $this->property($pool, 'pool'));
    }

    private function property(object $object, string $name): mixed
    {
        return new \ReflectionProperty($object, $name)->getValue($object);
    }

    private function prodKernel(): Kernel
    {
        return new class('prod', false, $this->directory) extends Kernel {
            public function __construct(
                string $environment,
                bool $debug,
                private readonly string $scratch,
            ) {
                parent::__construct($environment, $debug);
            }

            #[\Override]
            public function getProjectDir(): string
            {
                return \dirname(__DIR__, 2);
            }

            #[\Override]
            public function getCacheDir(): string
            {
                return $this->scratch.'/cache';
            }

            #[\Override]
            public function getBuildDir(): string
            {
                return $this->scratch.'/build';
            }

            #[\Override]
            protected function build(ContainerBuilder $container): void
            {
                parent::build($container);
                $container->addCompilerPass(new class implements CompilerPassInterface {
                    #[\Override]
                    public function process(ContainerBuilder $container): void
                    {
                        $container->getDefinition('mcp.session.store')->setPublic(true);
                        $container->getDefinition('cache.mcp.sessions')->setPublic(true);
                    }
                });
            }
        };
    }
}
