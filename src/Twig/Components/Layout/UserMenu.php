<?php

declare(strict_types=1);

namespace App\Twig\Components\Layout;

use App\Security\Roles;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Authenticated-user dropdown that anchors in the top nav.
 *
 * Builds the visible item list dynamically so each item can be
 * suppressed for one of two reasons:
 *
 * 1. The acting user does not hold the role gating that item.
 * 2. The target route is not registered (because the PR that adds
 *    it has not landed yet on the running branch).
 *
 * The component is rendered as `<twig:Layout:UserMenu />` from the
 * base template and is wrapped there in `{% if app.user %}` so the
 * `App\Entity\User` accessor in {@see self::getDisplayName()} is
 * safe.
 */
#[AsTwigComponent]
final class UserMenu
{
    /**
     * @param Security        $security used for role checks on individual items
     * @param RouterInterface $router   used to resolve route names — `RouteNotFoundException` makes the item invisible
     */
    public function __construct(
        private readonly Security $security,
        private readonly RouterInterface $router,
    ) {
    }

    /**
     * Display name shown on the dropdown trigger button.
     *
     * The base template guards the whole component with
     * `{% if app.user %}`, so `Security::getUser()` is guaranteed
     * to return an `App\Entity\User` whenever this method runs.
     *
     * @return string the authenticated user's display name
     */
    public function getDisplayName(): string
    {
        $user = $this->security->getUser();
        \assert($user instanceof \App\Entity\User);

        return $user->getName();
    }

    /**
     * Section list rendered by the template.
     *
     * Each section is an associative array with a `label_key`
     * (translation key for the section heading) and `items` (list of
     * link arrays). An empty `items` list omits the entire section.
     *
     * @return array<int, array{label_key: string, items: list<array{label_key: string, url: string}>}>
     */
    public function getSections(): array
    {
        $sections = [];

        $userItems = array_values(array_filter([
            $this->item('nav.user.profile_edit', 'app_profile_edit'),
            $this->item('nav.user.logout', 'app_logout'),
        ]));
        if ([] !== $userItems) {
            $sections[] = ['label_key' => 'nav.user.section_user', 'items' => $userItems];
        }

        $adminItems = array_values(array_filter([
            $this->item('nav.user.admin_organization', 'app_admin_organization_index', Roles::ADMIN),
            $this->item('nav.user.admin_users', 'app_admin_users', Roles::DOMAIN_MANAGER),
            $this->item('nav.user.admin_assistants', 'app_admin_assistants', Roles::DOMAIN_MANAGER),
            $this->item('nav.user.admin_settings', 'app_admin_settings_site', Roles::ADMIN),
        ]));
        if ([] !== $adminItems) {
            $sections[] = ['label_key' => 'nav.user.section_admin', 'items' => $adminItems];
        }

        return $sections;
    }

    /**
     * Build a single menu item, returning null when it should be hidden.
     *
     * @param string      $labelKey  translation key for the item label
     * @param string      $routeName Symfony route name to resolve
     * @param string|null $role      role required to see this item, or null when always allowed for authenticated users
     *
     * @return array{label_key: string, url: string}|null the item, or null when role-denied or route-missing
     */
    private function item(string $labelKey, string $routeName, ?string $role = null): ?array
    {
        if (null !== $role && !$this->security->isGranted($role)) {
            return null;
        }

        try {
            $url = $this->router->generate($routeName);
        } catch (RouteNotFoundException) {
            return null;
        }

        return ['label_key' => $labelKey, 'url' => $url];
    }
}
