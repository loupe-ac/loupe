<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Messenger\CheckForgeRepository;
use App\Module\Forge\Messenger\CheckTrigger;
use App\Module\Forge\Repository\ForgeRepositoryCheckRepository;
use App\Module\Forge\Service\RepositoryChecks;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RepositoryChecksTest extends KernelTestCase
{
    private const string NOW = '2026-10-27 12:00:00';

    private EntityManagerInterface $em;

    /** @var \ArrayObject<int, object> */
    private \ArrayObject $dispatched;

    private RepositoryChecks $checks;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $repository = self::getContainer()->get(ForgeRepositoryCheckRepository::class);
        self::assertInstanceOf(ForgeRepositoryCheckRepository::class, $repository);

        $this->dispatched = new \ArrayObject();
        $bus = new readonly class($this->dispatched) implements MessageBusInterface {
            /** @param \ArrayObject<int, object> $dispatched */
            public function __construct(
                private \ArrayObject $dispatched,
            ) {
            }

            #[\Override]
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched->append($message);

                return new Envelope($message);
            }
        };
        $this->checks = new RepositoryChecks($repository, $bus, new MockClock(self::NOW));
    }

    public function test_no_stored_check_is_not_fresh(): void
    {
        self::assertFalse($this->checks->isFresh(null, new \DateTimeImmutable(self::NOW)));
    }

    public function test_a_check_never_read_is_not_fresh(): void
    {
        self::assertFalse($this->checks->isFresh($this->check(null, null), new \DateTimeImmutable(self::NOW)));
    }

    #[DataProvider('ages')]
    public function test_the_age_of_a_read_is_the_newer_of_checked_and_attempted(?string $checkedAt, ?string $attemptedAt, bool $fresh): void
    {
        $check = $this->check(
            null === $checkedAt ? null : new \DateTimeImmutable($checkedAt),
            null === $attemptedAt ? null : new \DateTimeImmutable($attemptedAt),
        );

        self::assertSame($fresh, $this->checks->isFresh($check, new \DateTimeImmutable(self::NOW)));
    }

    /** @return iterable<string, array{?string, ?string, bool}> */
    public static function ages(): iterable
    {
        yield 'checked a minute ago' => ['2026-10-27 11:59:00', null, true];
        yield 'checked 4 minutes 59 seconds ago' => ['2026-10-27 11:55:01', null, true];
        yield 'checked exactly 5 minutes ago' => ['2026-10-27 11:55:00', null, false];
        yield 'checked an hour ago' => ['2026-10-27 11:00:00', null, false];
        yield 'a failed attempt a minute ago counts' => ['2026-10-27 11:00:00', '2026-10-27 11:59:00', true];
        yield 'a failed attempt alone counts' => [null, '2026-10-27 11:58:00', true];
        yield 'an old attempt after an old read' => ['2026-10-27 11:00:00', '2026-10-27 11:10:00', false];
        yield 'an old attempt before a recent read' => ['2026-10-27 11:59:00', '2026-10-27 11:10:00', true];
    }

    public function test_a_page_open_with_a_fresh_read_dispatches_nothing(): void
    {
        $repository = $this->repository();
        $check = new ForgeRepositoryCheck($repository, $repository->project);
        $check->checkedAt = new \DateTimeImmutable('2026-10-27 11:58:00');
        $this->em->persist($check);
        $this->em->flush();

        self::assertFalse($this->checks->requestPageOpenCheck($repository));
        self::assertCount(0, $this->dispatched);
    }

    public function test_a_page_open_with_a_stale_read_dispatches_a_page_open_check(): void
    {
        $repository = $this->repository();
        $check = new ForgeRepositoryCheck($repository, $repository->project);
        $check->checkedAt = new \DateTimeImmutable('2026-10-27 10:00:00');
        $this->em->persist($check);
        $this->em->flush();

        self::assertTrue($this->checks->requestPageOpenCheck($repository));
        self::assertEquals([new CheckForgeRepository((string) $repository->id, CheckTrigger::PageOpen)], $this->dispatched->getArrayCopy());
    }

    public function test_a_page_open_with_no_stored_check_dispatches_a_page_open_check(): void
    {
        $repository = $this->repository();

        self::assertTrue($this->checks->requestPageOpenCheck($repository));
        self::assertEquals([new CheckForgeRepository((string) $repository->id, CheckTrigger::PageOpen)], $this->dispatched->getArrayCopy());
    }

    private function check(?\DateTimeImmutable $checkedAt, ?\DateTimeImmutable $attemptedAt): ForgeRepositoryCheck
    {
        $repository = $this->repository();
        $check = new ForgeRepositoryCheck($repository, $repository->project);
        $check->checkedAt = $checkedAt;
        $check->attemptedAt = $attemptedAt;

        return $check;
    }

    private function repository(): ForgeRepository
    {
        $owner = new User(fullName: 'Owner', email: 'checks-'.uniqid().'@example.com', password: 'x');
        $project = new Project($owner, 'checks-'.uniqid());
        $repository = new ForgeRepository($project, 'github', 'ext-'.uniqid(), 'acme/widgets', ForgeRepositorySource::Hook);
        $this->em->persist($owner);
        $this->em->persist($project);
        $this->em->persist($repository);
        $this->em->flush();

        return $repository;
    }
}
