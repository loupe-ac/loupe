<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Service;

use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Deletion\AccountPurger;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Service\ApiKeyAccountPurger;
use App\Module\Project\Service\ProjectAccountPurger;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ApiKeyAccountPurgerTest extends KernelTestCase
{
    public function test_it_removes_the_keys_of_the_user_alone(): void
    {
        self::bootKernel();
        $scenario = new OAuthScenario(static::getContainer());
        $departing = $scenario->createUser('api-key-purge-departing@example.com');
        $other = $scenario->createUser('api-key-purge-other@example.com');
        $this->persistKey($departing, 'First');
        $this->persistKey($departing, 'Second');
        $kept = $this->persistKey($other, 'Kept');

        self::assertSame(3, $this->countKeys());

        $purger = static::getContainer()->get(ApiKeyAccountPurger::class);
        self::assertInstanceOf(ApiKeyAccountPurger::class, $purger);
        $purger->purge($departing, new AccountDeletionCleanup());

        self::assertSame([(string) $kept->id], $this->keyIds());
    }

    public function test_it_runs_after_the_project_purger_that_clears_the_entity_manager(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $purger = $container->get(ApiKeyAccountPurger::class);
        self::assertInstanceOf(ApiKeyAccountPurger::class, $purger);
        $projectPurger = $container->get(ProjectAccountPurger::class);
        self::assertInstanceOf(ProjectAccountPurger::class, $projectPurger);

        self::assertGreaterThan($projectPurger->deletionOrder(), $purger->deletionOrder());
    }

    public function test_the_ordered_chain_removes_the_keys_with_the_account(): void
    {
        self::bootKernel();
        $departing = new OAuthScenario(static::getContainer())->createUser('api-key-purge-chain@example.com');
        $this->persistKey($departing, 'Chained');

        self::assertSame(1, $this->countKeys());

        $accountPurger = static::getContainer()->get(AccountPurger::class);
        self::assertInstanceOf(AccountPurger::class, $accountPurger);
        $accountPurger->purge($departing);

        self::assertSame(0, $this->countKeys());
    }

    private function persistKey(User $owner, string $name): ApiKey
    {
        $secret = ApiKey::PREFIX.bin2hex(random_bytes(32));
        $key = new ApiKey($owner, $name, ApiKey::hashOf($secret), ApiKey::displayStartOf($secret));
        $this->em()->persist($key);
        $this->em()->flush();

        return $key;
    }

    private function countKeys(): int
    {
        return \count($this->keyIds());
    }

    /** @return list<string> */
    private function keyIds(): array
    {
        return array_map(strval(...), $this->em()->getConnection()->fetchFirstColumn('SELECT id FROM api_keys ORDER BY id'));
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
