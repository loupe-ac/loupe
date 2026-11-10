<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Settings\RepositoryRules;
use App\Module\Forge\Settings\RepositorySettings;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Workflow\Contract\SuggestionMark;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/** One repository whose plan refuses rulesets, one that can have them and one the App no longer reaches, so the Repositories tab shows each state. */
#[When('dev')]
#[When('test')]
final readonly class DevRepositoryCheckSeeder implements DevSeederInterface
{
    public const string KEY = 'github-repository-checks';
    public const string PATH_REFUSED = 'acme/free-plan-app';
    public const string PATH_CAPABLE = 'acme/ruleset-app';
    public const string PATH_NO_ACCESS = 'acme/no-access-app';

    public function __construct(
        private ProjectRepository $projects,
        private ForgeRepositoryRepository $forgeRepositories,
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevProjectSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $project = $this->projects->find(DevProjectSeeder::lifecycleProjectId());
        if (!$project instanceof Project) {
            return;
        }

        $this->repository($context, $project, self::PATH_REFUSED, 'github-repo/refused', new RepositorySettings('main', ['squash'], null, new RepositoryRules(false, false, null, [], [], false, false), [], [], false, 'Upgrade to GitHub Pro or make this repository public to enable this feature.'));
        $this->repository($context, $project, self::PATH_CAPABLE, 'github-repo/capable', new RepositorySettings('main', ['squash'], 4242, new RepositoryRules(true, false, null, ['lint', 'tests', 'Loupe / Approval'], [4242], true, true), [], ['lint', 'extra-check'], false));
        $this->repository($context, $project, self::PATH_NO_ACCESS, 'github-repo/no-access', new RepositorySettings('main', ['squash'], null, new RepositoryRules(true, false, null, [], [], false, false), [], [], false), $context->now->modify('-3 days'));
        $this->em->flush();
    }

    private function repository(SeedContext $context, Project $project, string $path, string $seedKey, RepositorySettings $settings, ?\DateTimeImmutable $accessLostAt = null): void
    {
        $id = DevSeedId::of($seedKey);
        if ($this->forgeRepositories->find($id) instanceof ForgeRepository) {
            return;
        }

        $repository = new ForgeRepository($project, 'github', $seedKey, $path, ForgeRepositorySource::Installation, '4242');
        $repository->accessLostAt = $accessLostAt;
        $context->ids->assign($repository, $id);
        $this->em->persist($repository);

        $check = new ForgeRepositoryCheck($repository, $project);
        $check->checkedAt = $context->now;
        $check->settings = json_decode(json_encode($settings, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        $check->items = [['key' => 'merge-method', 'mark' => SuggestionMark::Blocking->value, 'state' => ForgeRepositoryCheck::STATE_FITS, 'gaps' => [], 'parameters' => []]];
        $this->em->persist($check);
    }

    /** The project with no repository, for the empty twin of a preview. */
    public static function emptyProjectId(): Uuid
    {
        return DevProjectSeeder::devProjectId();
    }
}
