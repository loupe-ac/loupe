<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Api;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\AnalysisScope;
use App\Module\Insights\Entity\AnalysisTopic;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use App\Tests\Support\ApiKeyCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** The analysis routes of the public API under /api/v1/projects/{project}/analyses. */
final class AnalysisApiTest extends WebTestCase
{
    use InsightsScenario;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = $this->em();
        $this->owner = $this->user($this->em, 'analysis-api-'.uniqid().'@example.com');
        $this->project = $this->project($this->em, $this->owner, 'Analysis API');
        $this->token = ApiKeyCredential::mint(static::getContainer(), $this->owner);
    }

    public function test_the_list_walks_the_analyses_newest_first_by_cursor(): void
    {
        $oldest = $this->analysis('2026-10-01 09:00:00');
        $middle = $this->analysis('2026-10-02 09:00:00');
        $newest = $this->analysis('2026-10-03 09:00:00');

        $this->get($this->base().'?limit=2');
        self::assertResponseStatusCodeSame(200);
        $first = $this->json();
        self::assertSame([(string) $newest->id, (string) $middle->id], array_column($first['items'], 'id'));
        self::assertIsString($first['next']);

        $this->get($first['next']);
        self::assertResponseStatusCodeSame(200);
        $second = $this->json();
        self::assertSame([(string) $oldest->id], array_column($second['items'], 'id'));
        self::assertNull($second['next']);
    }

    public function test_the_walk_does_not_skip_analyses_that_share_a_creation_time(): void
    {
        $ids = [];
        foreach (range(1, 3) as $ignored) {
            $ids[] = (string) $this->analysis('2026-10-01 09:00:00')->id;
        }

        $seen = [];
        $url = $this->base().'?limit=1';
        while (null !== $url) {
            $this->get($url);
            $page = $this->json();
            $seen = [...$seen, ...array_column($page['items'], 'id')];
            $url = $page['next'];
        }

        sort($ids);
        sort($seen);
        self::assertSame($ids, $seen);
    }

    public function test_one_analysis_carries_its_scope_and_its_proposals(): void
    {
        $analysis = $this->analysis('2026-10-01 09:00:00');
        $this->seedProposal($this->em, $analysis, ProposalKind::Card, 0, ['title' => 'Cache']);

        $this->get($this->base().'/'.$analysis->id);

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame((string) $analysis->id, $body['id']);
        self::assertSame('cost', $body['topic']);
        self::assertSame('ninety-days', $body['scope']['range']);
        self::assertNull($body['costUsd']);
        self::assertCount(1, $body['proposals']);
        self::assertSame('card', $body['proposals'][0]['kind']);
        self::assertSame('proposed', $body['proposals'][0]['state']);
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
    }

    public function test_an_analysis_of_another_project_and_an_unknown_one_answer_the_same(): void
    {
        $other = $this->project($this->em, $this->owner, 'Other project');
        $foreign = new Analysis($other, AnalysisTopic::Cost, new AnalysisScope(MetricRange::All), null, 'sonnet', 'medium', new \DateTimeImmutable('2026-10-01 09:00:00'));
        $this->em->persist($foreign);
        $this->em->flush();

        foreach ([(string) $foreign->id, (string) Uuid::v7()] as $id) {
            $this->get($this->base().'/'.$id);
            self::assertResponseStatusCodeSame(404);
            self::assertSame('analysis_not_found', $this->json()['code']);
        }

        $this->get($this->base().'/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_bad_cursor_a_bad_limit_a_stranger_and_no_token_are_refused(): void
    {
        $stranger = ApiKeyCredential::mint(static::getContainer(), $this->user($this->em, 'analysis-api-stranger-'.uniqid().'@example.com'));

        $this->get($this->base().'?cursor=nonsense');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);

        $this->get($this->base().'?limit=0');
        self::assertResponseStatusCodeSame(422);

        $this->get($this->base(), $stranger);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);

        $this->get($this->base(), null);
        self::assertResponseStatusCodeSame(401);
    }

    private function analysis(string $createdAt): Analysis
    {
        $analysis = new Analysis($this->project, AnalysisTopic::Cost, new AnalysisScope(MetricRange::NinetyDays), null, 'sonnet', 'medium', new \DateTimeImmutable($createdAt));
        $this->em->persist($analysis);
        $this->em->flush();

        return $analysis;
    }

    private function base(): string
    {
        return '/api/v1/projects/'.$this->project->id.'/analyses';
    }

    private function get(string $url, ?string $token = ''): void
    {
        $bearer = '' === $token ? $this->token : $token;
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $url, server: null === $bearer ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
