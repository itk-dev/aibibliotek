<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Called by the kernel's own APP_ENV check, never from application
     * code. It overrides the framework's default, which returns an empty
     * list and therefore enforces nothing. Static analysis cannot trace
     * the call because it is made from the trait this class uses.
     *
     * @return list<string> An array of allowed values for APP_ENV
     *
     * @phpstan-ignore method.unused
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
