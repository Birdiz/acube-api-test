<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        if ('test' !== $this->environment) {
            return;
        }

        /*
         * A queued job has to outlive the request that queued it — the premise of
         * the 202. Kernel::handle() arms a reset of every `kernel.reset` service
         * for the next boot, and a test client boots once per request, which would
         * empty the in-memory queue before the drain ever saw it.
         */
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                if ($container->hasDefinition('messenger.transport.in_memory.factory')) {
                    $container->getDefinition('messenger.transport.in_memory.factory')
                        ->clearTag('kernel.reset');
                }
            }
        });
    }
}
