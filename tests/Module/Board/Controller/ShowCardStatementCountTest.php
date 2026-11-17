<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardValue;
use App\Module\Board\Entity\Forge;
use App\Module\Bridge\Entity\CardHold;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\PauseKind;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\AcceptedTerms;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The full card page reads each shared row once, so a reader that repeats a read fails here. */
final class ShowCardStatementCountTest extends WebTestCase
{
    use WorkflowProjects;

    public function test_the_full_card_page_sends_no_statement_twice(): void
    {
        $client = static::createClient();
        $project = $this->workflowProject('card-page-count');
        $this->bindLifecycle($project);
        $owner = $project->owner;
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());

        $parent = $this->card($project, 'in-progress');
        $card = $this->card($project, 'in-progress');
        $card->parent = $parent;
        $child = $this->card($project, 'next');
        $child->parent = $card;
        $this->em()->persist(new CardPullRequest($card, 'https://github.com/acme/app/pull/1', Forge::GitHub, 'acme/app', 1));
        $pullRequest = new ForgePullRequest($project, 'github', 'acme/app', 1);
        $pullRequest->headSha = str_repeat('a', 40);
        $this->em()->persist($pullRequest);
        $this->em()->persist(new CardValue($card, 'tech-design-interactive', true, Actor::System));
        $this->em()->persist(new CardHold($project, $card->id ?? throw new \LogicException('Stored.'), $owner, new \DateTimeImmutable()));
        $this->em()->persist(new CardPause($card, $project, 'rule', 'test-rule', PauseKind::Rule, new \DateTimeImmutable()));
        $this->em()->flush();
        $url = '/projects/'.$project->id.'/board/cards/'.$card->id;
        $this->em()->clear();

        $client->loginUser($owner);
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();

        $statements = [];
        foreach ($holder->getData() as $queries) {
            foreach ($queries as $query) {
                $statements[] = preg_replace('/\s+/', ' ', $query['sql'].' '.json_encode($query['params'] ?? [])) ?? '';
            }
        }

        // Guard: the page ran the workflow facts and the pull request reads, so an empty list proves the memo and not a skipped read.
        self::assertNotEmpty(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'FROM forge_pull_requests')));
        self::assertNotEmpty(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'FROM board_card_values')));
        self::assertSame([], $this->repeated($statements));
    }

    /**
     * @param list<string> $statements
     *
     * @return list<string>
     */
    private function repeated(array $statements): array
    {
        $counts = array_count_values(array_filter(
            $statements,
            static fn (string $sql): bool => !str_starts_with($sql, 'SAVEPOINT') && !str_starts_with($sql, 'RELEASE SAVEPOINT') && !str_contains($sql, 'CURRENT_DATABASE'),
        ));

        return array_map(
            static fn (string $sql): string => substr($sql, 0, 240),
            array_keys(array_filter($counts, static fn (int $count): bool => $count > 1)),
        );
    }

    private function card(\App\Module\Project\Entity\Project $project, string $column): Card
    {
        $card = new Card(project: $project, column: $this->column($project, $column), title: 'Card '.uniqid(), body: '', number: random_int(1, 100000), type: 'feature', position: 0);
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
    }
}
