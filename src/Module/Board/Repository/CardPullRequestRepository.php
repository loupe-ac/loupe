<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\FactsPass;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<CardPullRequest> */
class CardPullRequestRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly FactsPass $factsPass,
    ) {
        parent::__construct($registry, CardPullRequest::class);
    }

    public function findOneInProject(string $id, Project $project): ?CardPullRequest
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->createQueryBuilder('link')
            ->join('link.card', 'card')
            ->andWhere('link.id = :id')
            ->andWhere('card.project = :project')
            ->setParameter('id', Uuid::fromString($id), UuidType::NAME)
            ->setParameter('project', $project)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every card of one project linked to one pull request. A pull request can
     * be linked from more than one card, so a delivery can concern several.
     *
     * @return list<CardPullRequest>
     */
    public function findForPullRequest(Uuid $projectId, Forge $forge, string $repository, int $number): array
    {
        return $this->createQueryBuilder('link')
            ->addSelect('card')
            ->join('link.card', 'card')
            ->andWhere('card.project = :project')
            ->andWhere('link.forge = :forge')
            ->andWhere('LOWER(link.repository) = :repository')
            ->andWhere('link.number = :number')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->setParameter('forge', $forge)
            ->setParameter('repository', mb_strtolower($repository))
            ->setParameter('number', $number)
            ->orderBy('card.number')
            ->addOrderBy('link.id')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<array{forge: string, repository: string, number: int}> $keys
     *
     * @return array<string, int> `forge/repository/number` => the lowest card number of the project that links that pull request
     */
    public function findCardNumbersOfPullRequests(Uuid $projectId, array $keys): array
    {
        if ([] === $keys) {
            return [];
        }
        $query = $this->createQueryBuilder('link')
            ->select('link.forge AS forge', 'LOWER(link.repository) AS repository', 'link.number AS number', 'MIN(card.number) AS cardNumber')
            ->join('link.card', 'card')
            ->andWhere('card.project = :project')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->groupBy('link.forge', 'link.repository', 'link.number');
        $any = $query->expr()->orX();
        foreach (array_values($keys) as $i => $key) {
            $any->add("link.forge = :forge$i AND LOWER(link.repository) = :repository$i AND link.number = :number$i");
            $query->setParameter("forge$i", Forge::from($key['forge']))
                ->setParameter("repository$i", mb_strtolower($key['repository']))
                ->setParameter("number$i", $key['number']);
        }

        $found = [];
        /** @var list<array{forge: Forge, repository: string, number: int, cardNumber: int|string}> $rows */
        $rows = $query->andWhere($any)->getQuery()->getResult();
        foreach ($rows as $row) {
            $key = $row['forge']->value.'/'.$row['repository'].'/'.$row['number'];
            $found[$key] = min($found[$key] ?? \PHP_INT_MAX, (int) $row['cardNumber']);
        }

        return $found;
    }

    /**
     * The GitHub pull requests one card links, as the database holds them.
     *
     * @return list<array{repository: string, number: int}>
     */
    public function findGitHubReferences(Card $card): array
    {
        /** @var list<array{repository: string, number: int}> $rows */
        $rows = $this->createQueryBuilder('link')
            ->select('link.repository', 'link.number')
            ->andWhere('link.card = :card')
            ->andWhere('link.forge = :forge')
            ->andWhere('link.repository IS NOT NULL')
            ->andWhere('link.number IS NOT NULL')
            ->setParameter('card', $card)
            ->setParameter('forge', Forge::GitHub)
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }

    /**
     * Every pull request link of one card as the database holds it now, read
     * past the identity map so a link added after the card loaded counts.
     *
     * @return list<array{forge: string, repository: ?string, number: ?int, added_at: \DateTimeImmutable, predates_link_times: bool}>
     */
    public function findCurrentKeys(Card $card): array
    {
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();

        return array_map(static fn (array $row): array => [
            'forge' => (string) $row['forge'],
            'repository' => null === $row['repository'] ? null : (string) $row['repository'],
            'number' => null === $row['number'] ? null : (int) $row['number'],
            'added_at' => Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($row['added_at'], $platform) ?? throw new \LogicException('A stored link has a time.'),
            'predates_link_times' => (bool) $row['predates_link_times'],
        ], $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT forge, repository, number, added_at, predates_link_times FROM board_card_pull_requests WHERE card_id = :card ORDER BY id',
            ['card' => (string) $card->id],
        ));
    }

    /**
     * The pull requests one card of the project links by number, in the order
     * of the links.
     *
     * @return list<array{forge: string, repository: string, number: int, url: string}>
     */
    public function findNumberedKeysOfCard(Uuid $projectId, Uuid $cardId): array
    {
        return array_map(static fn (array $row): array => [
            'forge' => (string) $row['forge'],
            'repository' => (string) $row['repository'],
            'number' => (int) $row['number'],
            'url' => (string) $row['url'],
        ], $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT link.forge, link.repository, link.number, link.url FROM board_card_pull_requests link
             JOIN board_cards card ON card.id = link.card_id
             WHERE link.card_id = :card AND card.project_id = :project AND link.repository IS NOT NULL AND link.number IS NOT NULL
             ORDER BY link.id',
            ['card' => (string) $cardId, 'project' => (string) $projectId],
        ));
    }

    /**
     * The GitHub pull requests the card links whose last forge read found them open, oldest link first.
     *
     * @return list<ForgePullRequest>
     */
    public function findOpenGitHubForCard(Card $card): array
    {
        return $this->factsPass->remember('open-github-pull-requests:'.$card->id, fn (): array => $this->readOpenGitHubForCard($card));
    }

    /** @return list<ForgePullRequest> */
    private function readOpenGitHubForCard(Card $card): array
    {
        /** @var list<ForgePullRequest> $pullRequests */
        $pullRequests = $this->getEntityManager()->createQuery(
            'SELECT pr FROM '.ForgePullRequest::class.' pr, '.CardPullRequest::class.' link
            WHERE link.card = :card AND pr.project = :project AND pr.forge = :forge
                AND pr.repository = LOWER(link.repository) AND pr.number = link.number AND pr.state = :open
            GROUP BY pr.id
            ORDER BY MIN(link.addedAt) ASC, pr.id ASC',
        )
            ->setParameter('card', $card)
            ->setParameter('project', $card->project)
            ->setParameter('forge', Forge::GitHub->value)
            ->setParameter('open', PullRequestState::Open)
            ->getResult();

        return $pullRequests;
    }

    /**
     * The card and every card of its project that links an open GitHub pull request of the card.
     *
     * @return list<string> card ids
     */
    public function findCardIdsSharingOpenPullRequests(Card $card): array
    {
        $projectId = $card->project->id ?? throw new \LogicException('A stored project has an id.');
        $cardIds = [(string) $card->id => (string) $card->id];
        foreach ($this->findOpenGitHubForCard($card) as $pullRequest) {
            foreach ($this->findForPullRequest($projectId, Forge::GitHub, $pullRequest->repository, $pullRequest->number) as $link) {
                $cardIds[(string) $link->card->id] = (string) $link->card->id;
            }
        }

        return array_values($cardIds);
    }

    /** Whether a child of the card links a pull request that the last forge read found merged into the branch. */
    public function hasChildMergedInto(Card $parent, string $baseBranch): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1
            FROM board_cards c
            JOIN board_card_pull_requests link ON link.card_id = c.id
            JOIN forge_pull_requests pr ON pr.project_id = c.project_id AND pr.forge = link.forge
                AND pr.repository = LOWER(link.repository) AND pr.number = link.number
            WHERE c.parent_card_id = :parent AND pr.state = :merged AND pr.base_branch = :base
            LIMIT 1',
            [
                'parent' => ($parent->id ?? throw new \LogicException('A stored card has an id.'))->toRfc4122(),
                'merged' => PullRequestState::Merged->value,
                'base' => $baseBranch,
            ],
        );
    }

    /**
     * The pull request that a child of the card links and that the last forge read found merged into the branch last.
     *
     * @return ?array{forge: string, repository: string, number: int}
     */
    public function findLastChildMergedInto(Card $parent, string $baseBranch): ?array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT pr.forge, pr.repository, pr.number
            FROM board_cards c
            JOIN board_card_pull_requests link ON link.card_id = c.id
            JOIN forge_pull_requests pr ON pr.project_id = c.project_id AND pr.forge = link.forge
                AND pr.repository = LOWER(link.repository) AND pr.number = link.number
            WHERE c.parent_card_id = :parent AND pr.state = :merged AND pr.base_branch = :base
            ORDER BY pr.merged_at DESC NULLS LAST, pr.id
            LIMIT 1',
            [
                'parent' => ($parent->id ?? throw new \LogicException('A stored card has an id.'))->toRfc4122(),
                'merged' => PullRequestState::Merged->value,
                'base' => $baseBranch,
            ],
        );

        return false === $row ? null : ['forge' => (string) $row['forge'], 'repository' => (string) $row['repository'], 'number' => (int) $row['number']];
    }

    /**
     * Every pull request that a child of the card links and that the last forge read found merged into the branch.
     *
     * @return list<array{forge: string, repository: string, number: int}>
     */
    public function findKeysOfChildrenMergedInto(Card $parent, string $baseBranch): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT pr.forge, pr.repository, pr.number
            FROM board_cards c
            JOIN board_card_pull_requests link ON link.card_id = c.id
            JOIN forge_pull_requests pr ON pr.project_id = c.project_id AND pr.forge = link.forge
                AND pr.repository = LOWER(link.repository) AND pr.number = link.number
            WHERE c.parent_card_id = :parent AND pr.state = :merged AND pr.base_branch = :base',
            [
                'parent' => ($parent->id ?? throw new \LogicException('A stored card has an id.'))->toRfc4122(),
                'merged' => PullRequestState::Merged->value,
                'base' => $baseBranch,
            ],
        );

        return array_map(static fn (array $row): array => ['forge' => (string) $row['forge'], 'repository' => (string) $row['repository'], 'number' => (int) $row['number']], $rows);
    }

    /**
     * Every pull request link URL of one card as the database holds it now, read past the identity map.
     *
     * @return list<string>
     */
    public function findCurrentUrls(Card $card): array
    {
        return array_map(static fn (mixed $url): string => (string) $url, $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT url FROM board_card_pull_requests WHERE card_id = :card ORDER BY id',
            ['card' => (string) $card->id],
        ));
    }

    /**
     * When each pull request that a card links opened and merged, as the last forge read found them. A card with
     * no read pull request has no key.
     *
     * @param list<Uuid> $cardIds
     *
     * @return array<string, list<array{openedAt: ?\DateTimeImmutable, mergedAt: ?\DateTimeImmutable}>> card id => one entry per pull request
     */
    public function findPullRequestTimesOfCards(Project $project, array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        $connection = $this->getEntityManager()->getConnection();
        /** @var list<array{card_id: string, opened_at: ?string, merged_at: ?string}> $rows */
        $rows = $connection->fetchAllAssociative(
            'SELECT c.id AS card_id, pr.opened_at, pr.merged_at
            FROM board_card_pull_requests link
            JOIN board_cards c ON c.id = link.card_id
            JOIN forge_pull_requests pr ON pr.project_id = c.project_id AND pr.forge = link.forge
                AND pr.repository = LOWER(link.repository) AND pr.number = link.number
            WHERE c.project_id = :project AND c.id IN (:cards)
            ORDER BY c.id, pr.opened_at, pr.number',
            [
                'project' => ($project->id ?? throw new \LogicException('Project has no id.'))->toRfc4122(),
                'cards' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds),
            ],
            ['cards' => ArrayParameterType::STRING],
        );

        $type = Type::getType(Types::DATETIME_IMMUTABLE);
        $platform = $connection->getDatabasePlatform();
        $times = [];
        foreach ($rows as $row) {
            $openedAt = $type->convertToPHPValue($row['opened_at'], $platform);
            $mergedAt = $type->convertToPHPValue($row['merged_at'], $platform);
            $times[$row['card_id']][] = [
                'openedAt' => $openedAt instanceof \DateTimeImmutable ? $openedAt : null,
                'mergedAt' => $mergedAt instanceof \DateTimeImmutable ? $mergedAt : null,
            ];
        }

        return $times;
    }

    /**
     * Points every link of one repository in one project at its new path, and
     * answers how many moved. The stored path is the key a later delivery joins on.
     */
    public function repoint(Uuid $projectId, Forge $forge, string $from, string $to): int
    {
        // A DQL update cannot join, so the project filter is a subquery.
        return (int) $this->createQueryBuilder('link')
            ->update()
            ->set('link.repository', ':to')
            ->andWhere(\sprintf('link.card IN (SELECT card.id FROM %s card WHERE card.project = :project)', Card::class))
            ->andWhere('link.forge = :forge')
            ->andWhere('LOWER(link.repository) = :from')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->setParameter('to', $to)
            ->setParameter('forge', $forge)
            ->setParameter('from', mb_strtolower($from))
            ->getQuery()
            ->execute();
    }

    /** The URL of the first link of the card to one pull request, as a person gave it. */
    public function findUrlOfPullRequest(Card $card, string $forge, string $repository, int $number): ?string
    {
        $url = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT url FROM board_card_pull_requests
             WHERE card_id = :card AND forge = :forge AND LOWER(repository) = :repository AND number = :number
             ORDER BY id LIMIT 1',
            ['card' => (string) $card->id, 'forge' => $forge, 'repository' => mb_strtolower($repository), 'number' => $number],
        );

        return \is_string($url) ? $url : null;
    }

    public function findUrlForUpdate(CardPullRequest $link): ?string
    {
        $row = $this->createQueryBuilder('link')
            ->select('link.url')
            ->andWhere('link.id = :id')
            ->setParameter('id', $link->id, UuidType::NAME)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return null === $row ? null : $row['url'];
    }
}
