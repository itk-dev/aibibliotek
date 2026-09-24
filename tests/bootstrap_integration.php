<?php

use App\DataFixtures\AssistantFixtures;
use App\DataFixtures\OrganizationFixtures;
use App\DataFixtures\SettingFixtures;
use App\DataFixtures\UserFixtures;
use App\Kernel;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

new Dotenv()->bootEnv(__DIR__.'/../.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Integration suite: build the schema and load baseline fixtures once.
// DAMA wraps each test in a DBAL transaction that rolls back at tearDown,
// so the bootstrap-loaded baseline survives across the whole run while
// per-test mutations stay isolated.
$kernel = new Kernel('test', (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
// `test.service_container` exposes private services in the test
// environment — the same accessor KernelTestCase::getContainer() uses.
// The container dump analysis reads is the dev one, where the service
// does not exist, so the lookup cannot be resolved statically.
// @phpstan-ignore symfonyContainer.serviceNotFound
$container = $kernel->getContainer()->get('test.service_container');
if (!$container instanceof ContainerInterface) {
    throw new RuntimeException('The "test.service_container" service is missing; the test environment is not booted.');
}

$em = $container->get('doctrine')->getManager();
if (!$em instanceof EntityManagerInterface) {
    throw new RuntimeException('The default Doctrine manager is not an ORM entity manager.');
}

$schemaTool = new SchemaTool($em);
$schemaTool->dropDatabase();
$schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());

// AssistantFixtures declares OrganizationFixtures as a dependency
// (each row's `organization` column is resolved by name from the
// seeded rows), so organizations must land in the DB before the
// assistant fixture runs its `findAll()` lookup.
$fixtureClasses = [
    UserFixtures::class,
    SettingFixtures::class,
    OrganizationFixtures::class,
    AssistantFixtures::class,
];

foreach ($fixtureClasses as $fixtureClass) {
    $fixture = $container->get($fixtureClass);
    if (!$fixture instanceof FixtureInterface) {
        throw new RuntimeException(sprintf('Fixture "%s" is not registered in the test container.', $fixtureClass));
    }

    $fixture->load($em);
}

$kernel->shutdown();
