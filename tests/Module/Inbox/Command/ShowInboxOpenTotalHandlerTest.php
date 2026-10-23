<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use App\Module\Inbox\Command\ShowInboxOpenTotalCommand;
use App\Module\Inbox\Command\ShowInboxOpenTotalHandler;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ShowInboxOpenTotalHandlerTest extends KernelTestCase
{
    use InboxScenario;

    private EntityManagerInterface $em;
    private ShowInboxOpenTotalHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $handler = self::getContainer()->get(ShowInboxOpenTotalHandler::class);
        self::assertInstanceOf(ShowInboxOpenTotalHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_it_sums_the_open_items_over_every_project_of_the_owner(): void
    {
        $owner = $this->signedUpUser($this->em, 'total-sum');
        $first = $this->inboxProject($this->em, $owner);
        $second = $this->inboxProject($this->em, $owner);
        $this->question($this->em, $first, 1);
        $this->todo($this->em, $first, 2);
        $this->question($this->em, $second, 1);

        self::assertSame(3, ($this->handler)(new ShowInboxOpenTotalCommand($owner)));
    }

    public function test_it_ignores_closed_items_and_the_projects_of_other_owners(): void
    {
        $owner = $this->signedUpUser($this->em, 'total-own');
        $project = $this->inboxProject($this->em, $owner);
        $this->question($this->em, $project, 1);
        $this->answered($this->em, $this->question($this->em, $project, 2));
        $stranger = $this->inboxProject($this->em, $this->signedUpUser($this->em, 'total-other'));
        $this->question($this->em, $stranger, 1);

        self::assertSame(1, ($this->handler)(new ShowInboxOpenTotalCommand($owner)));
    }

    public function test_it_is_zero_when_the_owner_has_no_project(): void
    {
        $owner = $this->signedUpUser($this->em, 'total-none');

        self::assertSame(0, ($this->handler)(new ShowInboxOpenTotalCommand($owner)));
    }
}
