<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Admin\Service\Dev\DevFeatureFlagSeeder;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\InboxSearchIndexer;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * The state of the account inbox with rows: the keys account holds one open
 * item in its project. The empty twin is the keys-empty account, which owns no
 * project.
 */
#[When('dev')]
#[When('test')]
final readonly class DevAccountInboxSeeder implements DevSeederInterface
{
    public const string KEY = 'account-inbox';

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private InboxItemRepository $inboxItems,
        private InboxSearchIndexer $inboxSearch,
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
        return [DevProjectSeeder::KEY, DevFeatureFlagSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $id = DevSeedId::of('inbox/item/account-inbox-keys');
        if ($this->em->find(InboxItem::class, $id) instanceof InboxItem) {
            return;
        }

        $project = $this->projects->find(DevProjectSeeder::keysProjectId())
            ?? throw new \LogicException('The keys project does not exist.');
        $item = new InboxItem(
            project: $project,
            number: $this->inboxItems->nextNumber($project),
            kind: InboxItemKind::Todo,
            title: 'Rotate the CI deploy key',
            blocking: false,
            origin: InboxAskOrigin::Agent,
            createdAt: $context->at(4_140),
        );
        $context->ids->assign($item, $id);
        $this->em->persist($item);
        $this->em->flush();
        $this->inboxSearch->index($item);
    }
}
