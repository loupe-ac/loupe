<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Command\RecordBridgeHeartbeatCommand;
use App\Module\Bridge\Command\RecordBridgeHeartbeatHandler;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Security\LoginLivenessInterface;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\StubLoginLiveness;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** A quiet bridge reads "login expired" only when the login behind its last heartbeat can no longer refresh. */
final class BridgeLoginLivenessTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_a_heartbeat_records_the_login_of_its_access_token_once_per_token(): void
    {
        self::bootKernel();
        $login = Uuid::v7();
        $stub = $this->stub($login, []);
        $owner = $this->user($this->em(), 'login-record@example.com');
        $bridgeId = Uuid::v4();

        $handler = $this->handler();
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accessTokenId: 'access-1'));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accessTokenId: 'access-1'));

        $bridge = $this->bridges()->findOneByOwnerAndId($owner, $bridgeId);
        self::assertSame('access-1', $bridge?->loginAccessTokenId);
        self::assertEquals($login, $bridge->loginId);
        self::assertSame(['access-1'], $stub->lookups);
    }

    public function test_a_heartbeat_with_no_token_id_keeps_the_stored_login(): void
    {
        self::bootKernel();
        $login = Uuid::v7();
        $this->stub($login, []);
        $owner = $this->user($this->em(), 'login-keep@example.com');
        $bridgeId = Uuid::v4();

        $handler = $this->handler();
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accessTokenId: 'access-1'));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        self::assertEquals($login, $this->bridges()->findOneByOwnerAndId($owner, $bridgeId)?->loginId);
    }

    public function test_a_new_token_with_no_tagged_login_keeps_the_stored_one(): void
    {
        self::bootKernel();
        $login = Uuid::v7();
        $stub = $this->stub($login, []);
        $owner = $this->user($this->em(), 'login-untagged@example.com');
        $bridgeId = Uuid::v4();

        $handler = $this->handler();
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accessTokenId: 'access-1'));
        $stub->login = null;
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accessTokenId: 'access-2'));

        $bridge = $this->bridges()->findOneByOwnerAndId($owner, $bridgeId);
        self::assertSame('access-2', $bridge?->loginAccessTokenId);
        self::assertEquals($login, $bridge->loginId);
    }

    public function test_only_a_quiet_bridge_with_an_expired_login_reads_login_expired(): void
    {
        self::bootKernel();
        $clock = new MockClock('2026-10-30 12:00:00');
        self::getContainer()->set('clock', $clock);
        $expired = Uuid::v7();
        $alive = Uuid::v7();
        $stub = $this->stub(null, [$expired->toRfc4122()]);
        $owner = $this->user($this->em(), 'login-liveness@example.com');
        $quietExpired = Uuid::v4();
        $quietAlive = Uuid::v4();
        $quietUnknown = Uuid::v4();
        $liveExpired = Uuid::v4();

        $handler = $this->handler();
        foreach ([[$quietExpired, 'a', $expired], [$quietAlive, 'b', $alive], [$quietUnknown, 'c', null], [$liveExpired, 'd', $expired]] as [$id, $token, $login]) {
            $stub->login = $login;
            $handler(new RecordBridgeHeartbeatCommand($owner, $id, [], 'b4e39aa7', accessTokenId: $token));
        }
        $clock->modify('+1 hour');
        $handler(new RecordBridgeHeartbeatCommand($owner, $liveExpired, [], 'b4e39aa7'));

        $statuses = $this->service(BridgeLiveness::class)->forOwner($owner, [$quietExpired, $quietAlive, $quietUnknown, $liveExpired]);

        self::assertTrue($statuses[$quietExpired->toRfc4122()]->quiet);
        self::assertTrue($statuses[$quietExpired->toRfc4122()]->loginExpired);
        self::assertTrue($statuses[$quietAlive->toRfc4122()]->quiet);
        self::assertFalse($statuses[$quietAlive->toRfc4122()]->loginExpired);
        self::assertFalse($statuses[$quietUnknown->toRfc4122()]->loginExpired);
        self::assertFalse($statuses[$liveExpired->toRfc4122()]->quiet);
        self::assertFalse($statuses[$liveExpired->toRfc4122()]->loginExpired, 'a live bridge never reads login expired');
        self::assertCount(1, $stub->asked, 'one call covers every quiet bridge');
        self::assertEqualsCanonicalizing([$expired->toRfc4122(), $alive->toRfc4122()], $stub->asked[0]);
    }

    public function test_a_live_page_asks_nothing(): void
    {
        self::bootKernel();
        $stub = $this->stub(Uuid::v7(), []);
        $owner = $this->user($this->em(), 'login-live@example.com');
        $bridgeId = Uuid::v4();
        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accessTokenId: 'access-1'));
        $stub->asked = [];

        $this->service(BridgeLiveness::class)->forOwner($owner, [$bridgeId]);

        self::assertSame([], $stub->asked);
    }

    /** @param list<string> $expired */
    private function stub(?Uuid $login, array $expired): StubLoginLiveness
    {
        $stub = new StubLoginLiveness($login, $expired);
        self::getContainer()->set(LoginLivenessInterface::class, $stub);

        return $stub;
    }

    private function handler(): RecordBridgeHeartbeatHandler
    {
        return $this->service(RecordBridgeHeartbeatHandler::class);
    }

    private function bridges(): BridgeRepository
    {
        return $this->service(BridgeRepository::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
