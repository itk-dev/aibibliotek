<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components\Layout;

use App\Twig\Components\Layout\UserMenu;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RouterInterface;

/**
 * Unit-level coverage of {@see UserMenu}'s "route is not
 * registered" fallback.
 *
 * Every menu item passes through `self::item()`, which catches
 * `RouteNotFoundException` from the router and returns null so
 * the item silently drops out of the rendered menu. The
 * integration tests only exercise the happy path where every
 * route exists, so this case is exercised here with a router
 * stub that throws for *every* route name.
 */
final class UserMenuRouteMissingTest extends TestCase
{
    // Verifies that when every routed item raises RouteNotFoundException, no sections render.
    public function testRouteNotFoundDropsItemsFromMenu(): void
    {
        $security = $this->createMock(Security::class);
        // Grant every role check so the only thing that can suppress an
        // item is the route-existence guard we're trying to exercise.
        $security->method('isGranted')->willReturn(true);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willThrowException(new RouteNotFoundException('test: route not registered'));

        $menu = new UserMenu($security, $router);

        self::assertSame([], $menu->getSections());
    }
}
