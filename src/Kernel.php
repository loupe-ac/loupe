<?php

namespace App;

use App\Mercure\MercureHubTimeoutPass;
use App\Module\OAuth\Grant\LoopbackPortAuthCodeGrantPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    // On a Mac every checkout shares one php-fpm container over a slow virtiofs
    // mount, so the test cache goes to container-local disk, one dir per checkout.
    // worktree-teardown.sh computes the same path to remove it.
    #[\Override]
    public function getCacheDir(): string
    {
        if ('test' !== $this->environment) {
            return parent::getCacheDir();
        }

        return sys_get_temp_dir().'/loupe-test-cache/'.hash('xxh3', $this->getProjectDir()).'/'.$this->environment;
    }

    #[\Override]
    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new MercureHubTimeoutPass());
        $container->addCompilerPass(new LoopbackPortAuthCodeGrantPass());
    }
}
