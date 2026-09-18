<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;

/**
 * Register {@see DevTemplateMarkerNodeVisitor} when running in dev.
 *
 * The extension is auto-tagged as `twig.extension` because it extends
 * Twig's AbstractExtension and the project autoconfigures services
 * from `src/`. Gating happens here — outside the dev environment
 * `getNodeVisitors()` returns an empty array, so the visitor never
 * runs and prod-compiled templates carry no marker overhead.
 */
final class DevTemplateMarkerExtension extends AbstractExtension
{
    /**
     * @param string $environment the kernel environment (`dev`, `prod`, `test`)
     */
    public function __construct(
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
    ) {
    }

    /**
     * Return the visitor only in the dev environment.
     *
     * @return array<int, \Twig\NodeVisitor\NodeVisitorInterface> the visitors
     *                                                            to register
     */
    public function getNodeVisitors(): array
    {
        if ('dev' !== $this->environment) {
            return [];
        }

        return [new DevTemplateMarkerNodeVisitor()];
    }
}
