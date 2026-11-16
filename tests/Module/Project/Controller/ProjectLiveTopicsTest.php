<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class ProjectLiveTopicsTest extends WebTestCase
{
    public function test_the_agents_the_documents_list_and_the_workshop_ask_for_the_same_topics(): void
    {
        $client = static::createClient();
        [$owner, $id] = $this->ownerWithProject('live-same@example.com');
        $client->loginUser($owner);

        $agentTopics = $this->topics($client->request(Request::METHOD_GET, '/projects/'.$id.'/agents'));
        $documentTopics = $this->topics($client->request(Request::METHOD_GET, '/projects/'.$id.'/documents'));
        $workshopTopics = $this->topics($client->request(Request::METHOD_GET, '/projects/'.$id));

        $builder = static::getContainer()->get(ProjectTopicBuilder::class);
        $projectId = Uuid::fromString($id);
        self::assertContains($builder->forBoard($projectId), $agentTopics);
        self::assertContains($builder->forDocuments($projectId), $agentTopics);
        self::assertSame($agentTopics, $documentTopics);
        self::assertSame($agentTopics, $workshopTopics);
    }

    public function test_an_account_page_keeps_the_topics_of_the_remembered_project(): void
    {
        $client = static::createClient();
        [$owner, $id] = $this->ownerWithProject('live-account@example.com');
        $client->loginUser($owner);

        $beforeVisit = $this->topics($client->request(Request::METHOD_GET, '/account/profile'));
        $client->request(Request::METHOD_GET, '/projects/'.$id.'/documents');
        $afterVisit = $this->topics($client->request(Request::METHOD_GET, '/account/profile'));

        $builder = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertNotContains($builder->forWorkerRuns(Uuid::fromString($id)), $beforeVisit);
        self::assertContains($builder->forWorkerRuns(Uuid::fromString($id)), $afterVisit);
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
        $em->flush();
        $id = (string) $project->id;
        $em->clear();

        return [$owner, $id];
    }
}
