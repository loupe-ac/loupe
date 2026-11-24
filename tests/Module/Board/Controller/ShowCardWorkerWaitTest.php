<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerWait;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Project\Security\DeniesProjectManage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** The open wait of a worker in the status box of the card page, and the three controls under it. */
final class ShowCardWorkerWaitTest extends WebTestCase
{
    use BoardScenario;
    use DeniesProjectManage;

    public function test_the_status_box_shows_the_time_limit_and_what_happens_at_it(): void
    {
        [$crawler] = $this->showWaitingCard('card-wait-fields@example.com');

        $box = $crawler->filter('[data-card-state="waiting"]');
        self::assertCount(1, $box);
        $labels = $box->filter('.lp-status-box__field dt')->each(static fn (Crawler $dt): string => trim($dt->text()));
        self::assertContains('Time limit', $labels);
        self::assertContains('At the limit', $labels);
        self::assertNotContains('What to do', $labels);
        self::assertMatchesRegularExpression('/60 min, until \d{2}:\d{2}/', $box->text());
        self::assertStringContainsString('learns that the wait timed out', $box->text());
    }

    public function test_a_manager_gets_the_three_controls_under_the_box(): void
    {
        [$crawler, $project, $cardId] = $this->showWaitingCard('card-wait-controls@example.com');

        $controls = $crawler->filter('.lp-card-overview__state > [data-card-wait-controls]');
        self::assertCount(1, $controls);
        self::assertCount(0, $crawler->filter('[data-card-wait-stranded]'));
        $resume = $controls->filter('form[data-card-wait-resume]');
        self::assertSame('/projects/'.$project->id.'/cards/'.$cardId.'/wait/resume', $resume->attr('action'));
        self::assertSame('Resume now', trim($resume->filter('button')->text()));
        self::assertStringContainsString('lp-btn--ghost', (string) $resume->filter('button')->attr('class'));

        $limit = $controls->filter('[data-card-wait-limit] form');
        self::assertSame('/projects/'.$project->id.'/cards/'.$cardId.'/wait/limit', $limit->attr('action'));
        self::assertSame('60', $limit->filter('input[type="number"]')->attr('value'));
        self::assertStringContainsString('Change the time limit', $controls->filter('[data-card-wait-limit] summary')->text());

        $cancel = $controls->filter('form[data-card-wait-cancel]');
        self::assertSame('/projects/'.$project->id.'/cards/'.$cardId.'/wait/cancel', $cancel->attr('action'));
        self::assertSame('Cancel the wait? The worker stops and does not resume.', $cancel->attr('data-turbo-confirm'));
        self::assertStringContainsString('lp-btn--danger-ghost', (string) $cancel->filter('button')->attr('class'));
        self::assertCount(1, $cancel->filter('input[name="_csrf_token"]'));
    }

    public function test_a_member_who_cannot_manage_sees_the_wait_and_no_controls(): void
    {
        [$crawler] = $this->showWaitingCard('card-wait-member@example.com', manage: false);

        self::assertCount(1, $crawler->filter('[data-card-state="waiting"]'));
        self::assertCount(0, $crawler->filter('[data-card-wait-controls]'));
    }

    public function test_a_confirmed_cancel_ends_the_wait_and_the_card_shows_no_controls(): void
    {
        [$crawler, $project, $cardId, $client] = $this->showWaitingCard('card-wait-cancel@example.com');

        $client->submit($crawler->filter('form[data-card-wait-cancel]')->form());

        self::assertResponseRedirects('/projects/'.$project->id.'/board/cards/'.$cardId);
        $wait = static::getContainer()->get(EntityManagerInterface::class)->getRepository(WorkerWait::class)->findOneBy(['cardId' => Uuid::fromString($cardId)]);
        self::assertSame(WorkerWaitEndReason::CancelledByPerson, $wait?->endReason);
        $after = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$cardId);
        self::assertCount(0, $after->filter('[data-card-wait-controls]'));
    }

    public function test_a_post_without_a_token_is_refused(): void
    {
        [, $project, $cardId, $client] = $this->showWaitingCard('card-wait-token@example.com');

        $client->request(Request::METHOD_POST, '/projects/'.$project->id.'/cards/'.$cardId.'/wait/cancel');

        self::assertResponseStatusCodeSame(403);
        $wait = static::getContainer()->get(EntityManagerInterface::class)->getRepository(WorkerWait::class)->findOneBy(['cardId' => Uuid::fromString($cardId)]);
        self::assertTrue($wait?->isOpen());
    }

    public function test_the_limit_form_moves_the_limit_and_a_refused_value_flashes_the_reason(): void
    {
        [$crawler, $project, $cardId, $client] = $this->showWaitingCard('card-wait-limit@example.com');
        $path = '/projects/'.$project->id.'/board/cards/'.$cardId;

        $form = $crawler->filter('[data-card-wait-limit] form')->form();
        $client->submit($form, ['change_wait_limit_form[minutes]' => '90']);
        self::assertResponseRedirects($path);
        $after = $client->followRedirect();
        self::assertMatchesRegularExpression('/90 min, until/', $after->filter('[data-card-state="waiting"]')->text());

        $form = $after->filter('[data-card-wait-limit] form')->form();
        $client->submit($form, ['change_wait_limit_form[minutes]' => '100000']);
        $refused = $client->followRedirect();
        self::assertStringContainsString('longer than the longest wait', $refused->text());
        self::assertMatchesRegularExpression('/90 min, until/', $refused->filter('[data-card-state="waiting"]')->text());
    }

    public function test_a_stranded_wait_shows_the_remedy_and_only_resume_now_and_cancel(): void
    {
        [$crawler, $project, $cardId] = $this->showWaitingCard('card-wait-stranded@example.com', stranded: true);

        $box = $crawler->filter('[data-card-state="stuck"]');
        self::assertCount(1, $box);
        self::assertStringContainsString('Bridge offline', $box->text());
        self::assertStringContainsString('Start the bridge, or cancel the wait.', $box->text());
        $labels = $box->filter('.lp-status-box__field dt')->each(static fn (Crawler $dt): string => trim($dt->text()));
        self::assertNotContains('Time limit', $labels);
        $controls = $crawler->filter('.lp-card-overview__state > [data-card-wait-controls]');
        self::assertCount(1, $controls);
        self::assertSame('/projects/'.$project->id.'/cards/'.$cardId.'/wait/resume', $controls->filter('form[data-card-wait-resume]')->attr('action'));
        self::assertCount(0, $controls->filter('[data-card-wait-limit]'));
        self::assertCount(1, $crawler->filter('[data-card-wait-stranded]'));
        self::assertSame('Cancel the wait? The worker stops and does not resume.', $controls->filter('form[data-card-wait-cancel]')->attr('data-turbo-confirm'));
    }

    public function test_a_confirmed_cancel_of_a_stranded_wait_stops_the_run(): void
    {
        [$crawler, $project, $cardId, $client] = $this->showWaitingCard('card-wait-stranded-cancel@example.com', stranded: true);

        $client->submit($crawler->filter('form[data-card-wait-cancel]')->form());

        self::assertResponseRedirects('/projects/'.$project->id.'/board/cards/'.$cardId);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $wait = $em->getRepository(WorkerWait::class)->findOneBy(['cardId' => Uuid::fromString($cardId)]);
        self::assertSame(WorkerRunState::Stopped, $em->find(WorkerRun::class, $wait?->runId)?->state);
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{0: Crawler, 1: Project, 2: string, 3: KernelBrowser}
     */
    private function showWaitingCard(string $email, bool $manage = true, bool $stranded = false): array
    {
        $client = static::createClient();
        $client->disableReboot();
        if (!$manage) {
            $this->denyManage();
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, $email);
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Waiting card');
        $cardId = $card->id ?? throw new \LogicException('Seeded.');
        $bridgeId = Uuid::v4();
        $opened = new \DateTimeImmutable('-12 minutes');
        $run = new WorkerRun($project, $bridgeId, WorkSubject::CARD, $cardId, $card->number, 'implementation', $stranded ? WorkerRunState::Waiting : WorkerRunState::Running, Uuid::v4(), startedAt: $opened);
        $em->persist($run);
        $em->flush();
        $wait = new WorkerWait($project, $cardId, $run->id ?? throw new \LogicException('Seeded.'), $run->id, $bridgeId, Uuid::v4(), [], $opened, $opened->modify('+60 minutes'));
        if ($stranded) {
            $wait->end(WorkerWaitEndReason::Condition, $opened->modify('+5 minutes'));
        }
        $em->persist($wait);
        $card->markers = [$stranded ? [
            'key' => 'wait-bridge-offline',
            'kind' => 'stuck',
            'label' => 'marker.wait_bridge_offline',
            'remedy' => 'marker.wait_bridge_offline.remedy',
            'params' => [],
            'since' => $opened->format(\DATE_ATOM),
        ] : [
            'key' => 'worker-waiting',
            'kind' => 'waiting',
            'label' => 'marker.worker_waiting',
            'remedy' => 'marker.worker_waiting.remedy',
            'params' => ['%conditions%' => 'the checks of pr:loupe#1801 to pass or fail'],
            'since' => $opened->format(\DATE_ATOM),
        ]];
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$cardId);
        self::assertResponseIsSuccessful();

        return [$crawler, $project, (string) $cardId, $client];
    }
}
