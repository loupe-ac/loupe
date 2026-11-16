<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Messenger\CheckForgeBranch;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The stored answer to "does this branch exist", per forge repository row and branch, so a card evaluation
 * never asks the forge. A push and a background check write it. A write that carries an older observation
 * than the stored one is dropped. Each read-modify-write runs under a PostgreSQL transaction lock of the branch.
 */
final readonly class ForgeBranchPresence
{
    public const string KEY_PREFIX = 'forge_branch.';
    public const int ABSENT_REFRESH_SECONDS = 600;
    public const int PRESENT_REFRESH_SECONDS = 86_400;
    public const int REQUEST_WINDOW_SECONDS = 120;
    public const int ENTRY_LIFETIME_SECONDS = 604_800;

    private const string ACCESS_PREFIX = 'forge_branch_access.';

    public function __construct(
        private Connection $connection,
        private MessageBusInterface $bus,

        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The stored answer, or null when none counts. When the answer is missing or old, it reserves and queues
     * one check, inside the caller's transaction, so a rollback leaves neither the reservation nor the message.
     */
    public function read(ForgeRepository $repository, string $branch): ?bool
    {
        $now = $this->now();
        $entry = $this->load($repository, $branch);
        $answer = $entry->answer($this->accessMark($repository));

        if (!$this->isOld($entry, $answer, $now) || !$this->canRequest($entry, $now)) {
            return $answer;
        }

        $this->inTransaction(function () use ($repository, $branch, $now): void {
            if (!$this->tryLock($repository, $branch)) {
                return;
            }

            $entry = $this->load($repository, $branch);
            if (!$this->canRequest($entry, $now)) {
                return;
            }

            $this->save($repository, $branch, $entry->withCheckRequestedAt($now));
            $this->bus->dispatch(new CheckForgeBranch((string) $repository->project->id, (string) $repository->id, $branch));
        });

        return $answer;
    }

    /** True when an evaluation asked for the branch, so a push may write its answer. */
    public function tracks(ForgeRepository $repository, string $branch): bool
    {
        return $this->cache->getItem($this->key($repository, $branch))->isHit();
    }

    /** True when the answer that counts changed. The write is dropped when the stored answer or the access mark is newer than $seenAt. */
    public function record(ForgeRepository $repository, string $branch, bool $exists, \DateTimeImmutable $seenAt): bool
    {
        $seen = self::time($seenAt);

        return $this->inTransaction(function () use ($repository, $branch, $exists, $seen): bool {
            $this->lock($repository, $branch);
            $entry = $this->load($repository, $branch);
            $mark = $this->accessMark($repository);
            if ((null !== $mark && $seen < $mark) || (null !== $entry->seenAt && $entry->seenAt > $seen)) {
                return false;
            }

            $before = $entry->answer($mark);
            $this->save($repository, $branch, $entry->withAnswer($exists, $seen, $this->now()));

            return ($before ?? false) !== $exists;
        });
    }

    /** Voids every answer of the repository seen before now. Call it inside the transaction that changes the forge access. */
    public function markAccessChanged(ForgeRepository $repository): void
    {
        $item = $this->cache->getItem($this->accessKey($repository));
        $item->set($this->now());
        $item->expiresAfter(null);
        $this->cache->save($item);
    }

    private function isOld(ForgeBranchEntry $entry, ?bool $answer, float $now): bool
    {
        if (null === $answer) {
            return true;
        }

        $age = $now - ($entry->checkedAt ?? 0.0);

        return $age >= ($answer ? self::PRESENT_REFRESH_SECONDS : self::ABSENT_REFRESH_SECONDS);
    }

    private function canRequest(ForgeBranchEntry $entry, float $now): bool
    {
        return null === $entry->checkRequestedAt || $now - $entry->checkRequestedAt >= self::REQUEST_WINDOW_SECONDS;
    }

    private function load(ForgeRepository $repository, string $branch): ForgeBranchEntry
    {
        $item = $this->cache->getItem($this->key($repository, $branch));
        $stored = $item->get();

        return $item->isHit() && \is_array($stored) ? ForgeBranchEntry::fromArray($stored) : new ForgeBranchEntry();
    }

    private function save(ForgeRepository $repository, string $branch, ForgeBranchEntry $entry): void
    {
        $item = $this->cache->getItem($this->key($repository, $branch));
        $item->set($entry->toArray());
        $item->expiresAfter(self::ENTRY_LIFETIME_SECONDS);
        $this->cache->save($item);
    }

    private function accessMark(ForgeRepository $repository): ?float
    {
        $item = $this->cache->getItem($this->accessKey($repository));
        $mark = $item->get();

        return $item->isHit() && (\is_float($mark) || \is_int($mark)) ? (float) $mark : null;
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function inTransaction(callable $work): mixed
    {
        if ($this->connection->isTransactionActive()) {
            return $work();
        }

        return $this->connection->transactional(static fn (): mixed => $work());
    }

    private function lock(ForgeRepository $repository, string $branch): void
    {
        $this->connection->executeStatement('SELECT pg_advisory_xact_lock(hashtext(?))', [$this->lockKey($repository, $branch)]);
    }

    private function tryLock(ForgeRepository $repository, string $branch): bool
    {
        return true === $this->connection->fetchOne('SELECT pg_try_advisory_xact_lock(hashtext(?))', [$this->lockKey($repository, $branch)]);
    }

    private function lockKey(ForgeRepository $repository, string $branch): string
    {
        return 'forge_branch:'.$repository->id.':'.sha1($branch);
    }

    private function key(ForgeRepository $repository, string $branch): string
    {
        return self::KEY_PREFIX.$repository->id.'.'.sha1($branch);
    }

    private function accessKey(ForgeRepository $repository): string
    {
        return self::ACCESS_PREFIX.$repository->id;
    }

    private function now(): float
    {
        return self::time($this->clock->now());
    }

    private static function time(\DateTimeImmutable $at): float
    {
        return (float) $at->format('U.u');
    }
}
