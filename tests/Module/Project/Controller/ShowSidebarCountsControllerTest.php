<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Mercure\CountChangeGate;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\SidebarCountsSignal;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Service\InboxCountsSignal;
use App\Module\Project\Entity\Project;
use App\Module\Project\Sidebar\SidebarCounters;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class ShowSidebarCountsControllerTest extends WebTestCase
{
    use InboxScenario;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->setInboxFlag(true);
    }

    public function test_a_stranger_is_refused(): void
    {
        $project = $this->inboxProject($this->em, $this->signedUpUser($this->em, 'sidebar-owner'));

        $this->client->loginUser($this->signedUpUser($this->em, 'sidebar-stranger'));
        $this->client->request(Request::METHOD_GET, $this->url($project));

        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_visitor_who_is_not_signed_in_is_sent_to_sign_in(): void
    {
        $project = $this->inboxProject($this->em, $this->signedUpUser($this->em, 'sidebar-anonymous'));

        $this->client->request(Request::METHOD_GET, $this->url($project));

        self::assertResponseRedirects();
    }

    public function test_a_page_with_no_version_gets_the_four_pills_as_one_stream(): void
    {
        $owner = $this->signedUpUser($this->em, 'sidebar-stream');
        $project = $this->inboxProject($this->em, $owner);
        $this->question($this->em, $project, 1);
        $this->question($this->em, $project, 2);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, $this->url($project));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/vnd.turbo-stream.html; charset=UTF-8');
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        $crawler = $this->client->getCrawler();
        foreach (['agents-count-'.$project->id, 'activity-open-count-'.$project->id, 'inbox-open-count-'.$project->id, 'inbox-open-total'] as $target) {
            self::assertCount(1, $crawler->filter('turbo-stream[action="replace"][target="'.$target.'"]'), $target);
        }
        self::assertSame('2', $crawler->filter('turbo-stream[target="inbox-open-count-'.$project->id.'"] [data-inbox-open-count]')->text());
        self::assertSame('2', $crawler->filter('turbo-stream[target="inbox-open-total"] turbo-frame#inbox-open-total [data-inbox-open-total]')->text());
    }

    public function test_the_stream_shows_the_open_runs_of_the_project(): void
    {
        $owner = $this->signedUpUser($this->em, 'sidebar-runs');
        $project = $this->inboxProject($this->em, $owner);
        $this->seedRunningRun($project);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, $this->url($project));

        self::assertSame('1', $this->client->getCrawler()->filter('turbo-stream[target="activity-open-count-'.$project->id.'"] [data-activity-open-count]')->text());
    }

    public function test_the_inbox_pills_are_absent_while_the_inbox_is_off(): void
    {
        $owner = $this->signedUpUser($this->em, 'sidebar-flag-off');
        $project = $this->inboxProject($this->em, $owner);
        $this->setInboxFlag(false);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, $this->url($project));

        self::assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        self::assertCount(1, $crawler->filter('turbo-stream[target="agents-count-'.$project->id.'"]'));
        self::assertCount(0, $crawler->filter('turbo-stream[target="inbox-open-total"]'));
        self::assertCount(0, $crawler->filter('turbo-stream[target="inbox-open-count-'.$project->id.'"]'));
    }

    public function test_matching_versions_answer_204_with_no_body(): void
    {
        $owner = $this->signedUpUser($this->em, 'sidebar-204');
        $project = $this->inboxProject($this->em, $owner);
        $versions = $this->versions($project, $owner);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, $this->url($project, $versions['agents'].'.'.$versions['inbox']));

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }

    public function test_a_moved_version_answers_the_pills_again(): void
    {
        $owner = $this->signedUpUser($this->em, 'sidebar-moved');
        $project = $this->inboxProject($this->em, $owner);
        $versions = $this->versions($project, $owner);
        $since = $versions['agents'].'.'.$versions['inbox'];
        $gate = $this->gate();
        $this->client->loginUser($owner);

        $gate->changed(SidebarCountsSignal::versionKey($project), static fn (): string => 'a different fingerprint');
        $this->client->request(Request::METHOD_GET, $this->url($project, $since));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->client->getCrawler()->filter('turbo-stream[target="agents-count-'.$project->id.'"]'));

        $versions = $this->versions($project, $owner);
        $since = $versions['agents'].'.'.$versions['inbox'];
        $gate->replaceVersion(InboxCountsSignal::ownerVersionKey($owner->id ?? throw new \LogicException('The owner has no id.')));
        $this->client->request(Request::METHOD_GET, $this->url($project, $since));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->client->getCrawler()->filter('turbo-stream[target="inbox-open-total"]'));
    }

    public function test_the_inbox_part_is_ignored_while_the_inbox_is_off(): void
    {
        $owner = $this->signedUpUser($this->em, 'sidebar-204-off');
        $project = $this->inboxProject($this->em, $owner);
        $this->setInboxFlag(false);
        $versions = $this->versions($project, $owner);
        self::assertSame('', $versions['inbox']);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, $this->url($project, $versions['agents'].'.'));

        self::assertResponseStatusCodeSame(204);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableSince(): iterable
    {
        yield 'a missing value' => [''];
        yield 'only dots' => ['.'];
        yield 'a version nobody holds' => ['01JZZZZZZZZZZZZZZZZZZZZZZZ.01JZZZZZZZZZZZZZZZZZZZZZZZ'];
        yield 'one part' => ['abc'];
        yield 'three parts' => ['a.b.c'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableSince')]
    public function test_a_value_that_matches_no_stored_version_answers_the_pills(string $since): void
    {
        $owner = $this->signedUpUser($this->em, 'sidebar-garbage');
        $project = $this->inboxProject($this->em, $owner);
        $this->versions($project, $owner);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, $this->url($project, $since));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->client->getCrawler()->filter('turbo-stream[target="agents-count-'.$project->id.'"]'));
    }

    public function test_a_page_that_rendered_before_its_version_was_lost_gets_the_pills(): void
    {
        $owner = $this->signedUpUser($this->em, 'sidebar-lost');
        $project = $this->inboxProject($this->em, $owner);
        $versions = $this->versions($project, $owner);
        $this->deleteItems($project, $owner);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, $this->url($project, $versions['agents'].'.'.$versions['inbox']));

        self::assertResponseIsSuccessful();
    }

    public function test_a_quiet_project_answers_204_from_its_first_view_also_after_it_lost_both_versions(): void
    {
        $owner = $this->signedUpUser($this->em, 'sidebar-quiet');
        $project = $this->inboxProject($this->em, $owner);
        $this->client->loginUser($owner);

        for ($view = 1; $view <= 2; ++$view) {
            $versions = $this->versions($project, $owner);
            self::assertNotSame('', $versions['agents'], 'view '.$view);
            self::assertNotSame('', $versions['inbox'], 'view '.$view);
            $this->client->request(Request::METHOD_GET, $this->url($project, $versions['agents'].'.'.$versions['inbox']));
            self::assertResponseStatusCodeSame(204, 'view '.$view);
            if (2 === $view) {
                self::assertSame($first, $versions, 'a quiet project keeps its versions');
            }
            $first = $versions;
        }

        $this->deleteItems($project, $owner);
        $afterLoss = $this->versions($project, $owner);
        $this->client->request(Request::METHOD_GET, $this->url($project, $afterLoss['agents'].'.'.$afterLoss['inbox']));
        self::assertResponseStatusCodeSame(204);
    }

    private function url(Project $project, ?string $since = null): string
    {
        return '/projects/'.$project->id.'/sidebar-counts'.(null === $since ? '' : '?since='.rawurlencode($since));
    }

    /** @return array{agents: string, inbox: string} */
    private function versions(Project $project, User $viewer): array
    {
        $versions = static::getContainer()->get(SidebarCounters::class);
        self::assertInstanceOf(SidebarCounters::class, $versions);

        return $versions->renderVersions($project, $viewer);
    }

    private function deleteItems(Project $project, User $owner): void
    {
        $pool = static::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->deleteItem(CountChangeGate::PREFIX.SidebarCountsSignal::versionKey($project));
        $pool->deleteItem(CountChangeGate::PREFIX.InboxCountsSignal::ownerVersionKey($owner->id ?? throw new \LogicException('The owner has no id.')));
    }

    private function gate(): CountChangeGate
    {
        $gate = static::getContainer()->get(CountChangeGate::class);
        self::assertInstanceOf(CountChangeGate::class, $gate);

        return $gate;
    }

    private function seedRunningRun(Project $project): void
    {
        $this->em->persist(new WorkerRun(
            project: $project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: Uuid::v7(),
            cardNumber: 1,
            workKind: 'plan',
            state: WorkerRunState::Running,
            runKey: null,
            sessionId: Uuid::v4(),
            startedAt: new \DateTimeImmutable('2026-01-01 10:00:00'),
            endedAt: new \DateTimeImmutable('2026-01-01 10:05:00'),
            exitCode: null,
            hasResult: null,
            failureReason: null,
            output: '',
            receivedAt: new \DateTimeImmutable(),
        ));
        $this->em->flush();
    }
}
