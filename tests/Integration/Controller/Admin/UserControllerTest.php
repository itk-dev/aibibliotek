<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\DataFixtures\UserFixtures;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end admin user-management surface at `/admin/users`.
 *
 * Drives the controller through the real voter, CSRF check, and
 * `UserApproval` service. All actor / target rows come from the
 * integration suite's `UserFixtures` baseline (see
 * `tests/bootstrap_integration.php`), and DAMA rolls back every
 * mutation between tests so the baseline survives across the run.
 */
final class UserControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAnonymousAccessReturnsUnauthorized(): void
    {
        $this->client->request('GET', '/admin/users');

        self::assertResponseStatusCodeSame(401);
    }

    public function testPlainUserGets403(): void
    {
        $this->loginAsApproved(UserFixtures::ALICE_EMAIL);

        $this->client->request('GET', '/admin/users');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesEveryUser(): void
    {
        $this->loginAsApproved(UserFixtures::ADMIN_EMAIL);

        $crawler = $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        // Admin sees rows across every fixture domain: alice (@example.test)
        // and pending (@aalborg.dk) both render.
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString(UserFixtures::ALICE_EMAIL, $body);
        self::assertStringContainsString(UserFixtures::PENDING_EMAIL, $body);
    }

    public function testDomainManagerSeesOnlySameDomainUsers(): void
    {
        $this->loginAsApproved(UserFixtures::DOMAIN_MANAGER_EMAIL);

        $crawler = $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        // Manager (@aarhus.dk) sees the same-domain admin and colleague
        // but not the cross-domain @aalborg.dk pending user.
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString(UserFixtures::ADMIN_EMAIL, $body);
        self::assertStringContainsString(UserFixtures::COLLEAGUE_EMAIL, $body);
        self::assertStringNotContainsString(UserFixtures::PENDING_EMAIL, $body);
    }

    public function testPendingSubrouteRedirectsToFilteredList(): void
    {
        $this->loginAsApproved(UserFixtures::ADMIN_EMAIL);

        $this->client->request('GET', '/admin/users/pending');

        self::assertResponseRedirects('/admin/users?status=pending');
    }

    public function testStatusFilterRendersOnlyMatchingRows(): void
    {
        $this->loginAsApproved(UserFixtures::ADMIN_EMAIL);

        $crawler = $this->client->request('GET', '/admin/users?status=pending');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString(UserFixtures::PENDING_EMAIL, $body);
        // Approved baseline alice should not appear in a `pending` filter.
        self::assertStringNotContainsString(UserFixtures::ALICE_EMAIL, $body);
    }

    public function testInvalidStatusFilterIsTreatedAsNoFilter(): void
    {
        $this->loginAsApproved(UserFixtures::ADMIN_EMAIL);

        $crawler = $this->client->request('GET', '/admin/users?status=garbage');

        self::assertResponseIsSuccessful();
        // alice (approved) is rendered regardless because the filter falls back to null.
        self::assertStringContainsString(UserFixtures::ALICE_EMAIL, $crawler->filter('body')->text());
    }

    public function testApproveActionFlipsStatusToApproved(): void
    {
        $userRepository = self::getContainer()->get(UserRepository::class);
        $target = $userRepository->findOneBy(['email' => UserFixtures::PENDING_EMAIL]);
        self::assertNotNull($target);
        self::assertSame(UserStatus::Pending, $target->getStatus());

        $this->loginAsApproved(UserFixtures::ADMIN_EMAIL);

        $crawler = $this->client->request('GET', '/admin/users?status=pending');
        // Scope to the target's own approve form by id — the fixture seeds
        // several pending rows, so picking by index would race.
        $form = $crawler->filter('form[action$="/'.$target->getId().'/approve"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/users?status=pending');

        $reloaded = $userRepository->find($target->getId());
        self::assertNotNull($reloaded);
        self::assertSame(UserStatus::Approved, $reloaded->getStatus());
    }

    public function testBlockActionFlipsStatusToBlocked(): void
    {
        $userRepository = self::getContainer()->get(UserRepository::class);
        // alice is an Approved fixture user with no elevated roles —
        // safe to block without tripping the last-admin guard.
        $target = $userRepository->findOneBy(['email' => UserFixtures::ALICE_EMAIL]);
        self::assertNotNull($target);

        $this->loginAsApproved(UserFixtures::ADMIN_EMAIL);

        $crawler = $this->client->request('GET', '/admin/users');
        $form = $crawler->filter('form[action$="/'.$target->getId().'/block"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/users');

        $reloaded = $userRepository->find($target->getId());
        self::assertNotNull($reloaded);
        self::assertSame(UserStatus::Blocked, $reloaded->getStatus());
    }

    public function testApproveActionRejectsInvalidCsrfToken(): void
    {
        $userRepository = self::getContainer()->get(UserRepository::class);
        $target = $userRepository->findOneBy(['email' => UserFixtures::PENDING_EMAIL]);
        self::assertNotNull($target);

        $this->loginAsApproved(UserFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/admin/users/'.$target->getId().'/approve', [
            '_token' => 'nope',
        ]);

        self::assertResponseStatusCodeSame(403);

        $reloaded = $userRepository->find($target->getId());
        self::assertNotNull($reloaded);
        self::assertSame(UserStatus::Pending, $reloaded->getStatus(), 'CSRF rejection must not have flipped the status.');
    }

    public function testBlockActionRejectsInvalidCsrfToken(): void
    {
        $userRepository = self::getContainer()->get(UserRepository::class);
        // Target must start Approved so we can assert the CSRF rejection
        // didn't flip it to Blocked.
        $target = $userRepository->findOneBy(['email' => UserFixtures::ALICE_EMAIL]);
        self::assertNotNull($target);

        $this->loginAsApproved(UserFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/admin/users/'.$target->getId().'/block', [
            '_token' => 'nope',
        ]);

        self::assertResponseStatusCodeSame(403);

        $reloaded = $userRepository->find($target->getId());
        self::assertNotNull($reloaded);
        self::assertSame(UserStatus::Approved, $reloaded->getStatus(), 'CSRF rejection must not have flipped the status.');
    }

    public function testApproveActionDeniedAcrossDomainsForDomainManager(): void
    {
        // Manager (@aarhus.dk) vs pending (@aalborg.dk) — exactly the
        // cross-domain configuration the voter must deny.
        $userRepository = self::getContainer()->get(UserRepository::class);
        $target = $userRepository->findOneBy(['email' => UserFixtures::PENDING_EMAIL]);
        self::assertNotNull($target);

        $this->loginAsApproved(UserFixtures::DOMAIN_MANAGER_EMAIL);

        // Direct POST against a cross-domain target — the voter on the
        // `IsGranted` attribute fails closed before the controller body runs,
        // regardless of the CSRF token shape.
        $this->client->request('POST', '/admin/users/'.$target->getId().'/approve', [
            '_token' => 'irrelevant',
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $reloaded = $userRepository->find($target->getId());
        self::assertNotNull($reloaded);
        self::assertSame(UserStatus::Pending, $reloaded->getStatus());
    }

    public function testBackParameterRespectsTheAdminScope(): void
    {
        $userRepository = self::getContainer()->get(UserRepository::class);
        $this->loginAsApproved(UserFixtures::ADMIN_EMAIL);

        // Crafted POST with an off-site `back` parameter — controller must ignore it.
        $crawler = $this->client->request('GET', '/admin/users');
        /** @var User $target */
        $target = $userRepository->findOneBy(['email' => UserFixtures::BOB_EMAIL]);
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/users/'.$target->getId().'/block', [
            '_token' => $token,
            'back' => 'https://evil.invalid/owned',
        ]);

        self::assertResponseRedirects('/admin/users');
    }

    // Verifies the Role column renders with the user's current role label.
    public function testAdminListRendersRoleColumn(): void
    {
        $this->loginAsApproved(UserFixtures::ADMIN_EMAIL);

        $crawler = $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        $headers = $crawler->filter('thead th')->each(fn ($th) => trim($th->text()));
        self::assertContains('Rolle', $headers, 'Role column header must render.');
        // The fixture manager renders with the "Domæne-ansvarlig" label.
        $bodyText = $crawler->filter('tbody')->text();
        self::assertStringContainsString('Domæne-ansvarlig', $bodyText);
    }

    // Tests that an admin sees the 'Promote to Admin' option in the dropdown.
    public function testAdminSeesPromoteToAdminOption(): void
    {
        $this->loginAsApproved(UserFixtures::ADMIN_EMAIL);

        $crawler = $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        $optionLabels = $crawler->filter('select option')->each(fn ($o) => trim($o->text()));
        self::assertContains('Forfrem til administrator', $optionLabels);
    }

    // Tests that a manager does NOT see the 'Promote to Admin' option for any user. The fixture colleague is a same-domain plain user, so the manager DOES get a dropdown for them — this is the strong form of the assertion, proving the option is conditionally filtered out rather than the dropdown simply not rendering.
    public function testManagerDoesNotSeePromoteToAdminOption(): void
    {
        $this->loginAsApproved(UserFixtures::DOMAIN_MANAGER_EMAIL);

        $crawler = $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        $optionLabels = $crawler->filter('select option')->each(fn ($o) => trim($o->text()));
        // A dropdown must exist (proving the manager has at least one
        // actionable target) and it must not include the admin option.
        self::assertNotEmpty($optionLabels, 'Manager must have at least one dropdown to make this assertion meaningful.');
        self::assertNotContains('Forfrem til administrator', $optionLabels);
    }

    // Verifies the dropdown is omitted entirely for admin rows when the actor is a manager.
    public function testManagerSeesNoDropdownForAdminTargets(): void
    {
        $this->loginAsApproved(UserFixtures::DOMAIN_MANAGER_EMAIL);

        $crawler = $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        // The manager only sees same-domain rows. The admin row renders
        // its current role label, but no <select> next to it.
        $adminRow = $crawler->filter('tbody tr:contains("'.UserFixtures::ADMIN_EMAIL.'")');
        self::assertGreaterThan(0, $adminRow->count(), 'Admin row must render for an in-domain manager.');
        self::assertCount(0, $adminRow->filter('select'), 'Manager must not be offered a dropdown on an admin row.');
    }

    // Ensures the block button disappears for admin targets when the actor is a manager — defense in depth against an oversight in the voter. The approve form is naturally absent on Approved targets, so this asserts only on the block path that the voter rule actually gates.
    public function testManagerSeesNoBlockButtonForAdminTargets(): void
    {
        $this->loginAsApproved(UserFixtures::DOMAIN_MANAGER_EMAIL);

        $crawler = $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        $adminRow = $crawler->filter('tbody tr:contains("'.UserFixtures::ADMIN_EMAIL.'")');
        self::assertGreaterThan(0, $adminRow->count(), 'Admin row must render for an in-domain manager.');
        self::assertCount(0, $adminRow->filter('form[action*="/block"]'), 'Manager must not see the block form on an admin row.');
    }

    // Ensures the last active admin cannot block themselves: the block service refuses, a localised error flash renders, and the admin remains Approved.
    public function testLastActiveAdminCannotBlockSelf(): void
    {
        // The fixture admin is the only ROLE_ADMIN row in the baseline,
        // so they're already the sole active administrator. No need to
        // strip anyone — submit the block form and observe the refusal.
        $this->loginAsApproved(UserFixtures::ADMIN_EMAIL);
        $userRepository = self::getContainer()->get(UserRepository::class);
        $admin = $userRepository->findOneBy(['email' => UserFixtures::ADMIN_EMAIL]);
        self::assertNotNull($admin);

        $crawler = $this->client->request('GET', '/admin/users');
        $form = $crawler->filter('form[action$="/'.$admin->getId().'/block"]')->form();
        $this->client->submit($form);

        // The controller redirects back to the list with an error
        // flash; following the redirect surfaces the flash render.
        $followup = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Mindst én administrator',
            $followup->filter('[role="alert"]')->text(),
            'Error flash explaining the refusal must render on the redirected page.',
        );

        $reloaded = $userRepository->find($admin->getId());
        self::assertNotNull($reloaded);
        self::assertSame(UserStatus::Approved, $reloaded->getStatus(), 'Last-admin self-block must not flip the status.');
    }

    // Verifies a hand-crafted POST to /admin/users/{adminId}/block as a manager actor returns 403 and does not flip the admin's status.
    public function testManagerCannotBlockAdminViaDirectPost(): void
    {
        $userRepository = self::getContainer()->get(UserRepository::class);
        $admin = $userRepository->findOneBy(['email' => UserFixtures::ADMIN_EMAIL]);
        self::assertNotNull($admin, 'UserFixtures must seed the admin baseline.');
        self::assertSame(UserStatus::Approved, $admin->getStatus(), 'Fixture admin must start Approved so we can assert the block is rejected.');

        $this->loginAsApproved(UserFixtures::DOMAIN_MANAGER_EMAIL);

        // Grab a valid token from the list page — the voter denies before
        // the CSRF check would matter, but using a real token rules out a
        // false negative coming from token validation.
        $crawler = $this->client->request('GET', '/admin/users');
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/users/'.$admin->getId().'/block', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);

        $reloaded = $userRepository->find($admin->getId());
        self::assertNotNull($reloaded);
        self::assertSame(UserStatus::Approved, $reloaded->getStatus(), 'Block on an admin must be denied before any status flip.');
    }

    private function loginAsApproved(string $email): void
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        \assert(null !== $user, 'Test user must be seeded by UserFixtures before login.');
        $this->client->loginUser($user);
    }
}
