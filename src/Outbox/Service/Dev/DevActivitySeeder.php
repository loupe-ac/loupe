<?php

declare(strict_types=1);

namespace App\Outbox\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Service\Dev\DevAccountSeeder;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Outbox\Entity\OutboxEvent;
use App\Outbox\Repository\OutboxEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * The activity page and the project outbox list show rows of outbox events.
 * The dev project holds enough events for three activity pages, with delivered,
 * failed and unsent rows. The unsent rows wait for a time far ahead, so the
 * drain leaves them in the outbox list. The lifecycle project stays empty.
 */
#[When('dev')]
#[When('test')]
final readonly class DevActivitySeeder implements DevSeederInterface
{
    public const string KEY = 'activity';

    public const int EVENT_COUNT = 47;

    private const int UNSENT_COUNT = 3;

    private const int FAILED_COUNT = 4;

    /** @var list<string> */
    private const array TYPES = [
        'board.card_moved',
        'document.published',
        'inbox.item_created',
        'worker.run_changed',
        'project.renamed',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private OutboxEventRepository $outboxEvents,
    ) {
    }

    /** The project that holds no events. */
    public static function emptyProjectId(): Uuid
    {
        return DevProjectSeeder::lifecycleProjectId();
    }

    public static function eventId(int $number): Uuid
    {
        return DevSeedId::of('outbox/event/dev-'.$number);
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevAccountSeeder::KEY, DevProjectSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $project = $this->projects->find(DevProjectSeeder::devProjectId());
        if (!$project instanceof Project) {
            throw new \LogicException('The dev project does not exist.');
        }

        for ($number = 1; $number <= self::EVENT_COUNT; ++$number) {
            if ($this->outboxEvents->find(self::eventId($number)) instanceof OutboxEvent) {
                continue;
            }
            $this->event($context, $project, $number);
        }
        $this->em->flush();
    }

    private function event(SeedContext $context, Project $project, int $number): void
    {
        $event = new OutboxEvent(
            project: $project,
            type: self::TYPES[$number % \count(self::TYPES)],
            topic: 'dev-seed/activity',
            payload: json_encode(['seed' => $number], \JSON_THROW_ON_ERROR),
            createdAt: $context->at($number * 600),
        );

        if ($number <= self::UNSENT_COUNT) {
            $event->nextAttemptAt = $context->now->modify('+1 year');
        } elseif ($number <= self::UNSENT_COUNT + self::FAILED_COUNT) {
            $event->recordPublishFailure('The hub refused the update.', $context->now->modify('+1 year'));
            $event->nextAttemptAt = $context->now->modify('+1 year');
        } else {
            $event->publishedAt = $context->at($number * 600 - 5);
        }

        $context->ids->assign($event, self::eventId($number));
        $this->em->persist($event);
    }
}
