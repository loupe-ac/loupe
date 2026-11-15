<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Insights\Entity\AnalysisState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\ExperimentScenario;
use App\Tests\Module\Insights\InsightsScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** The rows reads of the later pages answer Turbo Streams for the rows a page shows. */
final class LiveRowsControllersTest extends WebTestCase
{
    use ExperimentScenario;
    use InsightsScenario;

    private const string STREAM = 'text/vnd.turbo-stream.html';

    public function test_a_report_row_morphs_in_place_and_a_missing_one_goes(): void
    {
        [$client, $project] = $this->signedIn('rows-reports');
        $em = $this->em();
        $shown = $this->seedAnalysis($em, $project, AnalysisState::Done);
        $this->seedProposal($em, $shown);
        $ghost = (string) Uuid::v7();
        $this->seedAnalysis($em, $project);

        $this->read($client, $project, '/reports/rows', ['ids' => $shown->id.','.$ghost, 'since' => '2026-10-01T00:00:00+00:00', 'page' => 2]);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(self::STREAM, (string) $client->getResponse()->headers->get('Content-Type'));
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" method="morph" target="analysis-'.$shown->id.'">', $body);
        self::assertStringContainsString('data-live-row="'.$shown->id.'"', $body);
        self::assertStringContainsString('page=2', $body);
        self::assertStringContainsString('<turbo-stream action="remove" target="analysis-'.$ghost.'">', $body);
        self::assertStringContainsString('<turbo-stream action="update" target="analysis-newer">', $body);
        self::assertStringContainsString('2 newer reports are on the first page.', $body);
    }

    public function test_the_report_band_stays_empty_when_nothing_is_newer(): void
    {
        [$client, $project] = $this->signedIn('rows-reports-none');
        $analysis = $this->seedAnalysis($this->em(), $project);

        $this->read($client, $project, '/reports/rows', ['ids' => (string) $analysis->id, 'since' => '2026-10-08T00:00:00+00:00']);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="update" target="analysis-newer"><template></template></turbo-stream>', self::squash($body));
    }

    public function test_a_report_row_of_another_project_goes(): void
    {
        [$client, $project] = $this->signedIn('rows-reports-mine');
        $other = $this->boardProject($this->em(), $this->user($this->em(), 'rows-reports-theirs@example.com'));
        $theirs = $this->seedAnalysis($this->em(), $other);

        $this->read($client, $project, '/reports/rows', ['ids' => (string) $theirs->id]);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="remove" target="analysis-'.$theirs->id.'">', $body);
        self::assertStringNotContainsString('action="replace"', $body);
    }

    public function test_a_stranger_gets_no_report_rows(): void
    {
        [$client, $project] = $this->signedIn('rows-reports-owner');
        $stranger = $this->user($this->em(), 'rows-reports-stranger@example.com');
        $this->em()->clear();
        $client->loginUser($stranger);

        $client->request(Request::METHOD_GET, $this->base($project).'/reports/rows', ['ids' => (string) Uuid::v7()]);

        self::assertResponseStatusCodeSame(403);
    }

    /** @param array<string, string> $query */
    #[DataProvider('provideBadReads')]
    public function test_a_bad_read_is_refused(array $query): void
    {
        [$client, $project] = $this->signedIn('rows-bad-'.md5(json_encode($query, \JSON_THROW_ON_ERROR)));

        $this->read($client, $project, '/reports/rows', $query);
        self::assertResponseStatusCodeSame(400);

        $this->read($client, $project, '/experiments/model-test/cards/rows', $query);
        self::assertResponseStatusCodeSame(400);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function provideBadReads(): iterable
    {
        yield 'an id that is no id' => [['ids' => 'not-an-id']];
        yield 'one bad id among good ones' => [['ids' => Uuid::v7().',nope']];
        yield 'too many ids' => [['ids' => implode(',', array_map(static fn (): string => (string) Uuid::v7(), range(1, 101)))]];
        yield 'a time that does not parse' => [['ids' => (string) Uuid::v7(), 'since' => 'yesterday']];
    }

    public function test_the_card_rows_morph_remove_and_count_the_newer_cards(): void
    {
        [$client, $project] = $this->signedIn('rows-cards');
        $em = $this->em();
        $a = $this->experimentCard($em, $project, 1);
        $b = $this->experimentCard($em, $project, 2);
        $other = $this->experimentCard($em, $project, 3);
        $this->experimentRun($em, $project, $a, 'a', at: '-3 hours');
        $this->experimentRun($em, $project, $b, 'b', at: '-2 hours');
        $this->experimentRun($em, $project, $other, 'a', at: '-30 minutes');
        $ghost = (string) Uuid::v7();
        $since = new \DateTimeImmutable('-1 hour')->format(\DATE_ATOM);

        $this->read($client, $project, '/experiments/model-test/cards/rows', ['ids' => $a->id.','.$b->id.','.$ghost, 'since' => $since, 'variant' => 'a']);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(self::STREAM, (string) $client->getResponse()->headers->get('Content-Type'));
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" method="morph" target="experiment-card-'.$a->id.'">', $body);
        self::assertStringContainsString('data-live-row="'.$a->id.'"', $body);
        self::assertStringNotContainsString('target="experiment-card-'.$other->id.'"', $body);
        self::assertStringContainsString('<turbo-stream action="remove" target="experiment-card-'.$b->id.'">', $body, 'A card of another variant no longer matches the filter.');
        self::assertStringContainsString('<turbo-stream action="remove" target="experiment-card-'.$ghost.'">', $body);
        self::assertStringContainsString('<turbo-stream action="update" target="experiment-cards-newer">', $body);
        self::assertStringContainsString('1 card ran again and is now on the first page.', $body);
        self::assertStringContainsString('href="'.$this->base($project).'/experiments/model-test/cards?variant=a"', $body);
    }

    public function test_the_card_rows_of_an_unknown_experiment_are_not_found(): void
    {
        [$client, $project] = $this->signedIn('rows-cards-unknown');

        $this->read($client, $project, '/experiments/ghost-test/cards/rows', ['ids' => (string) Uuid::v7()]);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_stranger_gets_no_card_rows(): void
    {
        [$client, $project] = $this->signedIn('rows-cards-owner');
        $this->experimentRun($this->em(), $project, $this->experimentCard($this->em(), $project, 1), 'a');
        $stranger = $this->user($this->em(), 'rows-cards-stranger@example.com');
        $this->em()->clear();
        $client->loginUser($stranger);

        $client->request(Request::METHOD_GET, $this->base($project).'/experiments/model-test/cards/rows', ['ids' => (string) Uuid::v7()]);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_settings_check_answers_the_fingerprint_of_the_form_band(): void
    {
        [$client, $project] = $this->signedIn('rows-settings');
        $this->em()->clear();
        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $this->base($project).'/reports');
        $fingerprint = (string) $crawler->filter('[data-analytics-settings-region]')->attr('data-live-fingerprint');

        $client->request(Request::METHOD_GET, $this->base($project).'/reports/settings/fingerprint');

        self::assertResponseIsSuccessful();
        self::assertSame($fingerprint, trim((string) $client->getResponse()->getContent()));

        $client->request(Request::METHOD_GET, $this->base($project).'/reports');
        $client->submitForm('Save the settings', ['analytics_settings_form[model]' => 'opus']);
        $client->request(Request::METHOD_GET, $this->base($project).'/reports/settings/fingerprint');
        self::assertNotSame($fingerprint, trim((string) $client->getResponse()->getContent()));
    }

    public function test_a_stranger_gets_no_settings_check(): void
    {
        [$client, $project] = $this->signedIn('rows-settings-owner');
        $stranger = $this->user($this->em(), 'rows-settings-stranger@example.com');
        $this->em()->clear();
        $client->loginUser($stranger);

        $client->request(Request::METHOD_GET, $this->base($project).'/reports/settings/fingerprint');

        self::assertResponseStatusCodeSame(403);
    }

    /** @return array{KernelBrowser, Project} */
    private function signedIn(string $name): array
    {
        $client = static::createClient();
        $em = $this->em();

        return [$client, $this->boardProject($em, $this->user($em, $name.'@example.com'))];
    }

    /** @param array<string, string|int> $query */
    private function read(KernelBrowser $client, Project $project, string $path, array $query): void
    {
        $this->em()->clear();
        $client->loginUser($project->owner);
        $client->request(Request::METHOD_GET, $this->base($project).$path, $query);
    }

    private function base(Project $project): string
    {
        return '/projects/'.$project->id.'/analytics';
    }

    private static function squash(string $text): string
    {
        return (string) preg_replace('/>\s+</', '><', trim($text));
    }
}
