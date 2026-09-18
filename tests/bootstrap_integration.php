<?php

use App\DataFixtures\AssistantFixtures;
use App\DataFixtures\OrganizationFixtures;
use App\DataFixtures\SettingFixtures;
use App\DataFixtures\UserFixtures;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

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
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get('doctrine')->getManager();
\assert($em instanceof EntityManagerInterface);

$schemaTool = new SchemaTool($em);
$schemaTool->dropDatabase();
$schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());

// AssistantFixtures declares OrganizationFixtures as a dependency
// (each row's `organization` column is resolved by name from the
// seeded rows), so organizations must land in the DB before the
// assistant fixture runs its `findAll()` lookup.
$container->get(UserFixtures::class)->load($em);
$container->get(SettingFixtures::class)->load($em);
$container->get(OrganizationFixtures::class)->load($em);
$container->get(AssistantFixtures::class)->load($em);

$kernel->shutdown();
