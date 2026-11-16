<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\CurrentProjectProvider;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class ProjectLiveTopicsTest extends WebTestCase
{
    use BoardColumnFixtures;

    public function test_the_board_the_agents_the_documents_list_the_inbox_and_the_workshop_ask_for_the_same_topics(): void
    {
        $client = static::createClient();
        [$owner, $id] = $this->ownerWithProject('live-same@example.com');
        $this->switchInbox(true);
        $client->loginUser($owner);

        $boardTopics = $this->topics($client->request(Request::METHOD_GET, '/projects/'.$id.'/board'));
        $inboxTopics = $this->topics($client->request(Request::METHOD_GET, '/projects/'.$id.'/inbox'));
        $agentTopics = $this->topics($client->request(Request::METHOD_GET, '/projects/'.$id.'/agents'));
        $documentTopics = $this->topics($client->request(Request::METHOD_GET, '/projects/'.$id.'/documents'));
        $workshopTopics = $this->topics($client->request(Request::METHOD_GET, '/projects/'.$id));

        $builder = static::getContainer()->get(ProjectTopicBuilder::class);
        $projectId = Uuid::fromString($id);
        self::assertContains($builder->forBoard($projectId), $boardTopics);
        self::assertContains($builder->forDocuments($projectId), $boardTopics);
        self::assertContains($builder->forInbox($projectId), $boardTopics);
        self::assertSame($boardTopics, $inboxTopics);
        self::assertSame($boardTopics, $agentTopics);
        self::assertSame($boardTopics, $documentTopics);
        self::assertSame($boardTopics, $workshopTopics);
    }

    public function test_an_account_page_keeps_the_topics_of_the_remembered_project(): void
    {
        $client = static::createClient();
        [$owner, $id] = $this->ownerWithProject('live-account@example.com');
        $this->switchInbox(true);
        $client->loginUser($owner);

        $beforeVisit = $this->topics($client->request(Request::METHOD_GET, '/account/profile'));
        $client->request(Request::METHOD_GET, '/projects/'.$id.'/documents');
        $afterVisit = $this->topics($client->request(Request::METHOD_GET, '/account/profile'));

        $builder = static::getContainer()->get(ProjectTopicBuilder::class);
        $projectId = Uuid::fromString($id);
        foreach ([$builder->forWorkerRuns($projectId), $builder->forInbox($projectId), $builder->forDocuments($projectId)] as $topic) {
            self::assertNotContains($topic, $beforeVisit);
            self::assertContains($topic, $afterVisit);
        }
    }

    public function test_an_account_page_keeps_the_topics_of_the_project_in_the_last_visited_cookie(): void
    {
        $client = static::createClient();
        [$owner, $id] = $this->ownerWithProject('live-cookie@example.com');
        $this->switchInbox(true);
        $client->loginUser($owner);

        $beforeCookie = $this->topics($client->request(Request::METHOD_GET, '/account/profile'));
        $client->getCookieJar()->set(new Cookie(CurrentProjectProvider::LAST_VISITED_COOKIE, $id));
        $afterCookie = $this->topics($client->request(Request::METHOD_GET, '/account/profile'));

        $builder = static::getContainer()->get(ProjectTopicBuilder::class);
        $projectId = Uuid::fromString($id);
        foreach ([$builder->forWorkerRuns($projectId), $builder->forInbox($projectId), $builder->forDocuments($projectId)] as $topic) {
            self::assertNotContains($topic, $beforeCookie);
            self::assertContains($topic, $afterCookie);
        }
    }

    private function switchInbox(bool $enabled): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[InboxInstallFlags::FLAG_INBOX_ENABLED]->value = $enabled;
        $em->flush();

        $reader = static::getContainer()->get(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(ResetInterface::class, $reader);
        $reader->reset();
    }

    /**
     * @return list<string>
     */
    private function topics(Crawler $crawler): array
    {
        self::assertResponseIsSuccessful();
        $topics = $crawler->filter('form#mercure-subscriptions input[data-mercure-topic]')->each(static fn (Crawler $input): string => (string) $input->attr('value'));
        sort($topics);

        return $topics;
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{User, string}
     */
    private function ownerWithProject(string $email): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = new User(fullName: 'Riley Chen', email: $email, password: 'x');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $em->persist($owner);
        $project = new Project($owner, 'live');
        $em->persist($project);
        $this->seedColumns($project);
        $em->flush();
        $id = (string) $project->id;
        $em->clear();

        return [$owner, $id];
    }
}
