<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Repository;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\Board\Entity\Card;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<AgentReview> */
class AgentReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentReview::class);
    }

    /**
     * When each card of the project had an agent review that passed, oldest first. A card with none has no key.
     *
     * @param list<Uuid> $cardIds
     *
     * @return array<string, list<\DateTimeImmutable>> card id => times
     */
    public function findPassedAtOfCards(Project $project, array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        /** @var list<array{cardId: Uuid|string, createdAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.card) AS cardId', 'r.createdAt AS createdAt')
            ->where('r.project = :project AND r.card IN (:cards) AND r.conclusion = :conclusion')
            ->setParameter('project', $project)
            ->setParameter('cards', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds))
            ->setParameter('conclusion', AgentReviewConclusion::Success)
            ->orderBy('r.createdAt')
            ->addOrderBy('r.id')
            ->getQuery()
            ->getResult();

        $times = [];
        foreach ($rows as $row) {
            $times[(string) $row['cardId']][] = $row['createdAt'];
        }

        return $times;
    }

    /**
     * @param list<ForgePullRequest> $pullRequests
     *
     * @return list<AgentReview> the reviews of the card on those pull requests that no check shows yet, oldest first
     */
    public function findUnpostedOfCard(Card $card, array $pullRequests): array
    {
        if ([] === $pullRequests) {
            return [];
        }

        /** @var list<AgentReview> $reviews */
        $reviews = $this->createQueryBuilder('r')
            ->where('r.card = :card AND r.pullRequest IN (:pullRequests) AND r.postedAt IS NULL')
            ->setParameter('card', $card)
            ->setParameter('pullRequests', $pullRequests)
            ->orderBy('r.createdAt')
            ->addOrderBy('r.id')
            ->getQuery()
            ->getResult();

        return $reviews;
    }

    /**
     * The newest review of the current head of each pull request, whichever card asked for it. A review of an
     * older head counts when its change fingerprint equals the fingerprint of the current head.
     *
     * @param list<ForgePullRequest> $pullRequests
     *
     * @return array<string, AgentReview> keyed by pull request id; a pull request with no head or no review of its head is absent
     */
    public function findLatestOfHeads(array $pullRequests): array
    {
        $heads = [];
        $fingerprints = [];
        $withHead = [];
        foreach ($pullRequests as $pullRequest) {
            if (null === $pullRequest->headSha) {
                continue;
            }
            $key = (string) $pullRequest->id;
            $heads[$key] = mb_strtolower($pullRequest->headSha);
            $withHead[] = $pullRequest;
            if (null !== $pullRequest->changeFingerprint && null !== $pullRequest->changeFingerprintSha && mb_strtolower($pullRequest->changeFingerprintSha) === $heads[$key]) {
                $fingerprints[$key] = $pullRequest->changeFingerprint;
            }
        }
        if ([] === $heads) {
            return [];
        }

        $query = $this->createQueryBuilder('r')
            ->where('r.pullRequest IN (:pullRequests)')
            ->setParameter('pullRequests', $withHead)
            ->setParameter('heads', array_values(array_unique($heads)))
            ->orderBy('r.createdAt')
            ->addOrderBy('r.id');
        if ([] === $fingerprints) {
            $query->andWhere('LOWER(r.headSha) IN (:heads)');
        } else {
            $query->andWhere('LOWER(r.headSha) IN (:heads) OR r.changeFingerprint IN (:fingerprints)')
                ->setParameter('fingerprints', array_values(array_unique($fingerprints)));
        }
        /** @var list<AgentReview> $reviews */
        $reviews = $query->getQuery()->getResult();

        $latest = [];
        foreach ($reviews as $review) {
            $key = (string) $review->pullRequest->id;
            $print = $fingerprints[$key] ?? null;
            if (($heads[$key] ?? null) === mb_strtolower($review->headSha) || (null !== $print && $print === $review->changeFingerprint)) {
                $latest[$key] = $review;
            }
        }

        return $latest;
    }

    /** @return list<AgentReview> the reviews of the pull request that the history announced as overridden and has not ended */
    public function findAnnouncedOfPullRequest(ForgePullRequest $pullRequest): array
    {
        /** @var list<AgentReview> $reviews */
        $reviews = $this->createQueryBuilder('r')
            ->where('r.pullRequest = :pullRequest AND r.overrideAnnouncedFor IS NOT NULL')
            ->setParameter('pullRequest', $pullRequest)
            ->getQuery()
            ->getResult();

        return $reviews;
    }

    /**
     * @param list<Card>             $cards
     * @param list<ForgePullRequest> $pullRequests
     *
     * @return list<AgentReview> for each card and pull request pair, the newest review (by creation time, then id)
     */
    public function findLatestOfCards(array $cards, array $pullRequests): array
    {
        if ([] === $cards || [] === $pullRequests) {
            return [];
        }

        /** @var list<AgentReview> $reviews */
        $reviews = $this->createQueryBuilder('r')
            ->where('r.card IN (:cards) AND r.pullRequest IN (:pullRequests)')
            ->andWhere('NOT EXISTS (SELECT 1 FROM '.AgentReview::class.' n WHERE n.card = r.card AND n.pullRequest = r.pullRequest AND (n.createdAt > r.createdAt OR (n.createdAt = r.createdAt AND n.id > r.id)))')
            ->setParameter('cards', $cards)
            ->setParameter('pullRequests', $pullRequests)
            ->getQuery()
            ->getResult();

        return $reviews;
    }

    /** @return int the number of reviews deleted */
    public function deleteByProject(Project $project): int
    {
        $deleted = $this->getEntityManager()
            ->createQuery('DELETE App\Module\AgentReview\Entity\AgentReview r WHERE r.project = :project')
            ->setParameter('project', $project)
            ->execute();

        return \is_int($deleted) ? $deleted : 0;
    }
}
