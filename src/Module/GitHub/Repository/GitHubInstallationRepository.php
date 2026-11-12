<?php

declare(strict_types=1);

namespace App\Module\GitHub\Repository;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\GitHubDelivery;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<GitHubInstallation> */
final class GitHubInstallationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GitHubInstallation::class);
    }

    public function findOneByInstallationId(int $installationId): ?GitHubInstallation
    {
        return $this->findOneBy(['installationId' => $installationId]);
    }

    /**
     * Inserts the record unless one exists. A concurrent delivery may create the
     * same installation, and a unique violation would close the EntityManager.
     */
    public function insertIfAbsent(int $installationId, string $accountLogin, GitHubRepositorySelection $selection, ?int $accountId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO github_installations (id, installation_id, account_login, repository_selection, account_id, created_at) VALUES (:id, :installationId, :login, :selection, :accountId, :createdAt) ON CONFLICT (installation_id) DO NOTHING',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'installationId' => $installationId,
                'login' => $accountLogin,
                'selection' => $selection->value,
                'accountId' => $accountId,
                'createdAt' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * The installations that the installation rows of the project name, "No access" rows included.
     *
     * @return list<GitHubInstallation>
     */
    public function findByProject(Project $project): array
    {
        $ids = $this->installationIds(
            $this->getEntityManager()->createQueryBuilder()
                ->select('DISTINCT r.sourceRef')
                ->from(ForgeRepository::class, 'r')
                ->andWhere('r.project = :project')
                ->setParameter('project', $project),
        );

        return $this->byIds($ids);
    }

    /**
     * The installations that the installation rows of the owner's projects name.
     *
     * @return list<GitHubInstallation>
     */
    public function findByOwner(User $user): array
    {
        $ids = $this->installationIds(
            $this->getEntityManager()->createQueryBuilder()
                ->select('DISTINCT r.sourceRef')
                ->from(ForgeRepository::class, 'r')
                ->join('r.project', 'p')
                ->andWhere('p.owner = :user')
                ->setParameter('user', $user),
        );

        return $this->byIds($ids);
    }

    /** @return list<GitHubInstallation> every installation record that has no account id yet */
    public function findWithoutAccountId(): array
    {
        return array_values($this->findBy(['accountId' => null], ['createdAt' => 'ASC', 'id' => 'ASC']));
    }

    /** @return list<int> */
    private function installationIds(QueryBuilder $builder): array
    {
        $refs = $builder
            ->andWhere('r.forge = :forge')
            ->andWhere('r.source = :source')
            ->andWhere('r.sourceRef IS NOT NULL')
            ->setParameter('forge', GitHubDelivery::FORGE)
            ->setParameter('source', ForgeRepositorySource::Installation->value)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(intval(...), array_filter($refs, static fn (mixed $ref): bool => \is_string($ref) && ctype_digit($ref))));
    }

    /**
     * @param list<int> $ids
     *
     * @return list<GitHubInstallation>
     */
    private function byIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return array_values($this->createQueryBuilder('i')
            ->andWhere('i.installationId IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('i.createdAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
