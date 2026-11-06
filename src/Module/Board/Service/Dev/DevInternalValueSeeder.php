<?php

declare(strict_types=1);

namespace App\Module\Board\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardValueWriter;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectCreating;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Workflow\Contract\Actor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes a project that runs the Lifecycle workflow plus one internal card value, with a card that holds the value
 * On and a card that holds none. The shipped templates declare no internal value yet, so the seeder appends the
 * declaration to the template copy of this project alone. The card "lifecycle-values" of the Lifecycle project is
 * the twin whose template declares no internal value. A later seed finds each record by its fixed id and writes
 * only what is missing.
 */
#[When('dev')]
#[When('test')]
final readonly class DevInternalValueSeeder implements DevSeederInterface
{
    public const string KEY = 'board-internal-values';
    public const string PROJECT_NAME = 'Dev Project (internal value)';
    public const string VALUE_KEY = 'dev-internal-demo';

    private const array VALUE = [
        'key' => self::VALUE_KEY,
        'type' => 'bool',
        'label' => 'dev.value.internal_demo.label',
        'caption' => 'dev.value.internal_demo.caption',
        'help' => 'dev.value.internal_demo.help',
        'history' => 'dev.value.internal_demo.history',
        'visibility' => 'internal',
    ];

    public function __construct(
        private ProjectRepository $projects,
        private BoardColumnRepository $boardColumns,
        private CardRepository $cards,
        private Connection $connection,
        private CardValueWriter $values,
        private DevProjectSeeder $devProjects,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
    ) {
    }

    public static function projectId(): Uuid
    {
        return DevSeedId::of('board/project/internal-value');
    }

    public static function cardId(string $state): Uuid
    {
        return DevSeedId::of('board/card/internal-value-'.$state);
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevProjectSeeder::KEY, DevBoardSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $project = $this->project($context);
        if ($this->declareValue($project)) {
            // The managed binding still holds the copy that the project was bound with.
            $this->em->clear();
            $project = $this->projects->find(self::projectId()) ?? throw new \LogicException('The internal value project is missing.');
        }

        $card = $this->card($context, $project, 'on', 'A card whose internal value is On', 300);
        $this->card($context, $project, 'unset', 'A card whose internal value is unset', 310);
        $this->values->set($card, self::VALUE_KEY, true, Actor::System);

        $this->em->flush();
    }

    private function project(SeedContext $context): Project
    {
        $existing = $this->projects->find(self::projectId());
        if ($existing instanceof Project) {
            return $existing;
        }

        $this->devProjects->renameOldProject($context->devUser(), self::PROJECT_NAME);

        $project = new Project($context->devUser(), self::PROJECT_NAME, createdAt: $context->at(330));
        $context->ids->assign($project, self::projectId());
        $this->em->persist($project);
        $this->events->dispatch(new ProjectCreating($project, 'lifecycle'));
        $this->em->flush();

        return $project;
    }

    /**
     * Writes the template copy by SQL, because Board imports nothing of Workflow except its contract.
     *
     * @return bool whether this call wrote the declaration
     */
    private function declareValue(Project $project): bool
    {
        $projectId = $project->requireId()->toRfc4122();
        $stored = $this->connection->fetchOne('SELECT definition FROM workflow_bindings WHERE project_id = :project', ['project' => $projectId]);
        if (!\is_string($stored)) {
            throw new \LogicException('The internal value project has no workflow binding.');
        }
        $definition = json_decode($stored, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($definition)) {
            throw new \LogicException('The stored workflow definition is not an object.');
        }
        $declared = \is_array($definition['values'] ?? null) ? $definition['values'] : [];
        if (array_any($declared, static fn (mixed $value): bool => \is_array($value) && self::VALUE_KEY === ($value['key'] ?? null))) {
            return false;
        }

        $definition['values'] = [...$declared, self::VALUE];
        $this->connection->executeStatement(
            'UPDATE workflow_bindings SET definition = :definition WHERE project_id = :project',
            ['definition' => json_encode($definition, \JSON_THROW_ON_ERROR), 'project' => $projectId],
            ['definition' => ParameterType::STRING],
        );

        return true;
    }

    private function card(SeedContext $context, Project $project, string $state, string $title, int $secondsAgo): Card
    {
        $existing = $this->cards->find(self::cardId($state));
        if ($existing instanceof Card) {
            return $existing;
        }

        $columns = $this->boardColumns->findForProject($project);
        $column = array_find($columns, static fn (BoardColumn $column): bool => !$column->terminal) ?? throw new \LogicException('The project has no open column.');
        $card = new Card(
            project: $project,
            column: $column,
            title: $title,
            body: 'Shows the closed section of internal values on the Details tab.',
            number: $this->cards->nextNumber($project),
            type: 'feature',
            createdAt: $context->at($secondsAgo),
        );
        $context->ids->assign($card, self::cardId($state));
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }
}
