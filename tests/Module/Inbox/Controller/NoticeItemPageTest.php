<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxRepositoryMisfit;
use App\Module\Inbox\Service\InboxSearchIndexer;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** A notice on the inbox page: Loupe as its sender, its body, and no response form. */
final class NoticeItemPageTest extends WebTestCase
{
    use InboxScenario;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Project $project;
    private InboxItem $notice;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $owner = $this->signedUpUser($em, 'inbox-notice-page');
        $this->project = $this->inboxProject($em, $owner);
        $this->notice = new InboxItem(
            project: $this->project,
            number: 1,
            kind: InboxItemKind::Notice,
            title: 'A bridge rule races the app sync',
            blocking: false,
            origin: InboxAskOrigin::Loupe,
            body: "- `sync-behind` on bridge `01990000`\n\nRemove each rule on `pull_request.behind` from rules.yaml.",
        );
        $em->persist($this->notice);
        $em->flush();
        $this->setInboxFlag(true);
        $this->client->loginUser($owner);
    }

    public function test_an_open_notice_shows_its_body_from_loupe_with_no_form(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/inbox');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.lp-list-row__meta [data-inbox-source]', 'Loupe');
        self::assertSelectorExists('.lp-list-row [data-inbox-kind="notice"]');
        $item = $crawler->filter('#inbox-item-1');
        self::assertCount(1, $item);
        self::assertStringContainsString('sync-behind', $item->filter('.lp-inbox-item__body')->text());
        self::assertCount(0, $item->filter('form'));
        self::assertCount(0, $item->filter('.lp-inbox-item__blocking'));
    }

    public function test_a_closed_notice_says_loupe_closed_it(): void
    {
        $this->notice->state = InboxItemState::Resolved;
        $this->notice->closedBy = InboxCloser::Loupe;
        $this->notice->closeCause = InboxCloseCause::FactEnded;
        $this->notice->closedAt = new \DateTimeImmutable();
        $this->em->flush();
        $indexer = static::getContainer()->get(InboxSearchIndexer::class);
        self::assertInstanceOf(InboxSearchIndexer::class, $indexer);
        $indexer->index($this->notice);

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/inbox?q=races');

        self::assertResponseIsSuccessful();
        $editable = $crawler->filter('[data-inbox-item="1"] [data-inbox-editable]');
        self::assertCount(1, $editable);
        self::assertSame('no', $editable->attr('data-inbox-editable'));
        self::assertCount(0, $crawler->filter('[data-inbox-item="1"] form'));
    }

    public function test_an_open_repository_misfit_links_to_repositories_and_offers_check_again_for_each_blocking_repository(): void
    {
        $repositoryIds = ['0197c8a0-0000-7000-8000-0000000000a1', '0197c8a0-0000-7000-8000-0000000000a2'];
        $misfitNotice = new InboxItem(project: $this->project, number: 2, kind: InboxItemKind::Notice, title: 'acme/a, acme/b do not fit the workflow', blocking: false, origin: InboxAskOrigin::Loupe, body: 'New work is paused.');
        $this->em->persist($misfitNotice);
        $this->em->persist(new InboxRepositoryMisfit($this->project, $misfitNotice, new \DateTimeImmutable(), [
            ['id' => $repositoryIds[0], 'path' => 'acme/a', 'gaps' => 1],
            ['id' => $repositoryIds[1], 'path' => 'acme/b', 'gaps' => 2],
        ]));
        $this->em->flush();

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/inbox?item=2');

        self::assertResponseIsSuccessful();
        $item = $crawler->filter('#inbox-item-2');
        $guide = $item->filter('[data-inbox-repository-misfit-guide]');
        self::assertCount(1, $guide);
        self::assertSame('/projects/'.$this->project->id.'/settings/repositories', $guide->attr('href'));
        $checks = $item->filter('form[action$="/check"]');
        self::assertCount(2, $checks);
        foreach ($repositoryIds as $index => $repositoryId) {
            self::assertSame('/projects/'.$this->project->id.'/repositories/'.$repositoryId.'/check', $checks->eq($index)->attr('action'));
            self::assertNotSame('', $checks->eq($index)->filter('input[name="_csrf_token"]')->attr('value'));
        }
        self::assertSame('Check acme/a again', trim($checks->eq(0)->text()));
        self::assertCount(0, $crawler->filter('#inbox-item-1 [data-inbox-repository-misfit]'), 'Another notice shows no misfit actions.');
    }

    public function test_a_closed_repository_misfit_offers_no_action(): void
    {
        $misfitNotice = new InboxItem(project: $this->project, number: 2, kind: InboxItemKind::Notice, title: 'acme/a does not fit the workflow', blocking: false, origin: InboxAskOrigin::Loupe, body: 'New work is paused.');
        $misfitNotice->state = InboxItemState::Resolved;
        $misfitNotice->closedBy = InboxCloser::Loupe;
        $misfitNotice->closeCause = InboxCloseCause::FactEnded;
        $misfitNotice->closedAt = new \DateTimeImmutable();
        $this->em->persist($misfitNotice);
        $misfit = new InboxRepositoryMisfit($this->project, $misfitNotice, new \DateTimeImmutable(), [['id' => '0197c8a0-0000-7000-8000-0000000000a1', 'path' => 'acme/a', 'gaps' => 1]]);
        $misfit->endedAt = new \DateTimeImmutable();
        $this->em->persist($misfit);
        $this->em->flush();

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/inbox?item=2');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-inbox-repository-misfit]'));
    }
}
