<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Form\SetCardHumanReviewFormType;
use App\Module\Board\Repository\CardEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\Turbo\TurboBundle;

final class SetCardHumanReviewControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_the_details_tab_shows_the_switch_and_a_tooltip_that_links_to_the_workflow_page(): void
    {
        [$client, $em, $project, $card] = $this->scenario('human-review-show@example.com');
        $em->clear();

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $field = $crawler->filter('#card-panel-details [data-card-human-review]');
        self::assertCount(1, $field);
        self::assertSame('Requires human review', trim($field->filter('label')->text()));
        $switch = $field->filter('input.lp-switch[role="switch"]');
        self::assertCount(1, $switch);
        self::assertNull($switch->attr('checked'));
        self::assertNull($switch->attr('disabled'));
        self::assertSame('submit-on-change', $field->filter('form')->attr('data-controller'));
        self::assertStringStartsWith('See the rules', trim($field->filter('.lp-tooltip--interactive a.lp-tooltip__link')->text()));
        self::assertSame('/projects/'.$project->id.'/workflow', $field->filter('.lp-tooltip--interactive a.lp-tooltip__link')->attr('href'));
        self::assertSame('_top', $field->filter('.lp-tooltip--interactive a.lp-tooltip__link')->attr('data-turbo-frame'));
        self::assertCount(1, $field->filter('[role="tooltip"]#'.$switch->attr('aria-describedby')));
    }

    public function test_the_switch_shows_the_stored_state(): void
    {
        [$client, $em, $project, $card] = $this->scenario('human-review-on@example.com');
        $card->requiresHumanReview = true;
        $em->flush();
        $em->clear();

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertNotNull($crawler->filter('[data-card-human-review] input.lp-switch')->attr('checked'));
    }

    public function test_it_turns_the_switch_on_and_off_and_redirects_to_the_details_tab(): void
    {
        [$client, $em, $project, $card] = $this->scenario('human-review-set@example.com');
        $cardId = (string) $card->id;
        $em->clear();

        $this->post($client, (string) $project->id, $cardId, true);
        self::assertResponseRedirects('/projects/'.$project->id.'/board/cards/'.$cardId.'?tab=details');
        self::assertTrue($this->fresh($em, $cardId)->requiresHumanReview);
        self::assertSame(1, $this->events($em, $cardId));

        $this->post($client, (string) $project->id, $cardId, false);
        self::assertResponseRedirects('/projects/'.$project->id.'/board/cards/'.$cardId.'?tab=details');
        self::assertFalse($this->fresh($em, $cardId)->requiresHumanReview);
        self::assertSame(2, $this->events($em, $cardId));
    }

    public function test_a_stale_submit_of_the_same_state_changes_nothing(): void
    {
        [$client, $em, $project, $card] = $this->scenario('human-review-stale@example.com');
        $cardId = (string) $card->id;
        $em->clear();

        $this->post($client, (string) $project->id, $cardId, true);
        $this->post($client, (string) $project->id, $cardId, true);

        self::assertResponseRedirects();
        self::assertTrue($this->fresh($em, $cardId)->requiresHumanReview);
        self::assertSame(1, $this->events($em, $cardId));
    }

    public function test_a_turbo_request_gets_a_stream_that_replaces_the_field(): void
    {
        [$client, $em, $project, $card] = $this->scenario('human-review-stream@example.com');
        $cardId = (string) $card->id;
        $em->clear();

        $this->post($client, (string) $project->id, $cardId, true, TurboBundle::STREAM_MEDIA_TYPE);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(TurboBundle::STREAM_MEDIA_TYPE, (string) $client->getResponse()->headers->get('Content-Type'));
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="card-human-review-'.$cardId.'">', $content);
        self::assertMatchesRegularExpression('/<input[^>]*lp-switch[^>]*checked/', $content);
        self::assertTrue($this->fresh($em, $cardId)->requiresHumanReview);
    }

    public function test_a_form_with_a_bad_token_is_refused_and_changes_nothing(): void
    {
        [$client, $em, $project, $card] = $this->scenario('human-review-refused@example.com');
        $cardId = (string) $card->id;
        $em->clear();

        $client->request(
            Request::METHOD_POST,
            '/projects/'.$project->id.'/board/cards/'.$cardId.'/human-review',
            [SetCardHumanReviewFormType::nameFor($cardId) => ['requiresHumanReview' => '1', '_token' => 'wrong']],
            server: ['HTTP_REFERER' => 'http://localhost/projects/'.$project->id.'/board/cards/'.$cardId, 'HTTP_ACCEPT' => TurboBundle::STREAM_MEDIA_TYPE],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('was not saved', (string) $client->getResponse()->getContent());
        self::assertFalse($this->fresh($em, $cardId)->requiresHumanReview);
    }

    public function test_a_user_who_cannot_write_the_card_is_forbidden(): void
    {
        [$client, $em, $project, $card] = $this->scenario('human-review-owner@example.com');
        $stranger = $this->user($em, 'human-review-stranger@example.com');
        $cardId = (string) $card->id;
        $em->clear();

        $client->loginUser($stranger);
        $this->post($client, (string) $project->id, $cardId, true);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->fresh($em, $cardId)->requiresHumanReview);
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{KernelBrowser, EntityManagerInterface, \App\Module\Project\Entity\Project, Card}
     */
    private function scenario(string $email): array
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, $email);
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Reviewed');
        $client->loginUser($owner);

        return [$client, $em, $project, $card];
    }

    private function post(KernelBrowser $client, string $projectId, string $cardId, bool $value, ?string $accept = null): void
    {
        $fields = ['_token' => 'csrf-token'];
        if ($value) {
            $fields['requiresHumanReview'] = '1';
        }
        $client->request(
            Request::METHOD_POST,
            '/projects/'.$projectId.'/board/cards/'.$cardId.'/human-review',
            [SetCardHumanReviewFormType::nameFor($cardId) => $fields],
            server: ['HTTP_REFERER' => 'http://localhost/projects/'.$projectId.'/board/cards/'.$cardId] + (null === $accept ? [] : ['HTTP_ACCEPT' => $accept]),
        );
    }

    private function fresh(EntityManagerInterface $em, string $cardId): Card
    {
        $em->clear();

        return $em->find(Card::class, $cardId) ?? throw new \LogicException('The card is gone.');
    }

    private function events(EntityManagerInterface $em, string $cardId): int
    {
        return static::getContainer()->get(CardEventRepository::class)->count(['card' => $cardId, 'kind' => CardEventKind::HumanReviewChanged]);
    }
}
