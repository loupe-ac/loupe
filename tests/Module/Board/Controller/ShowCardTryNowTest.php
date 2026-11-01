<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Project\Security\ProjectVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** The Try now control of a Limited card, under the status box of the card page. */
final class ShowCardTryNowTest extends WebTestCase
{
    use BoardScenario;

    private const string BRIDGE = '0197a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    public function test_a_manager_gets_a_try_now_form_that_posts_to_the_account_of_the_bridge(): void
    {
        [$crawler, $project] = $this->showLimitedCard('card-try-now-manager@example.com', canTryNow: 1);

        $form = $crawler->filter('.lp-card-overview__state > form[data-card-try-now]');
        self::assertCount(1, $form);
        self::assertCount(0, $crawler->filter('[data-card-state="waiting"] [data-card-try-now]'));
        self::assertCount(1, $crawler->filter('.lp-card-overview__state > [data-card-state="waiting"] + form[data-card-try-now]'));
        self::assertStringContainsString('lp-btn--ghost', (string) $form->filter('button')->attr('class'));
        self::assertSame('/projects/'.$project->id.'/agents/'.self::BRIDGE.'/accounts/claude/try-now', $form->attr('action'));
        self::assertSame('post', $form->attr('method'));
        self::assertSame('_top', $form->attr('data-turbo-frame'));
        self::assertCount(1, $form->filter('input[name="_csrf_token"]'));
        self::assertSame('Try now', trim($form->filter('button[type="submit"]')->text()));
    }

    public function test_a_member_who_cannot_manage_sees_the_reset_time_and_no_button(): void
    {
        [$crawler] = $this->showLimitedCard('card-try-now-member@example.com', canTryNow: 1, manage: false);

        $box = $crawler->filter('[data-card-state="waiting"]');
        self::assertStringContainsString('resets', $box->text());
        self::assertGreaterThan(0, $box->filter('time[datetime]')->count());
        self::assertCount(0, $crawler->filter('[data-card-try-now]'));
    }

    public function test_a_bridge_that_cannot_try_now_shows_no_button(): void
    {
        [$crawler] = $this->showLimitedCard('card-try-now-capability@example.com', canTryNow: 0);

        self::assertCount(1, $crawler->filter('[data-card-state="waiting"]'));
        self::assertCount(0, $crawler->filter('[data-card-try-now]'));
    }

    public function test_a_limit_with_no_known_bridge_shows_no_button(): void
    {
        [$crawler] = $this->showLimitedCard('card-try-now-no-bridge@example.com', canTryNow: 1, bridgeId: '');

        self::assertCount(1, $crawler->filter('[data-card-state="waiting"]'));
        self::assertCount(0, $crawler->filter('[data-card-try-now]'));
    }

    /** @param non-empty-string $email
     * @return array{0: Crawler, 1: \App\Module\Project\Entity\Project} */
    private function showLimitedCard(string $email, int $canTryNow, string $bridgeId = self::BRIDGE, bool $manage = true): array
    {
        $client = static::createClient();
        if (!$manage) {
            $this->denyManage();
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, $email);
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Limited card');
        $card->markers = [
            [
                'key' => 'usage-limit',
                'kind' => 'waiting',
                'label' => 'marker.usage_limit',
                'remedy' => 'marker.usage_limit.remedy',
                'params' => [
                    '%cause%' => 'limit',
                    '%account%' => 'claude',
                    '%window%' => 'week',
                    '%use%' => 100,
                    '%stopAt%' => 95,
                    '%resetsAt%' => new \DateTimeImmutable('+1 day')->getTimestamp(),
                    '%bridgeId%' => $bridgeId,
                    '%canTryNow%' => $canTryNow,
                ],
                'since' => new \DateTimeImmutable('-1 hour')->format(\DATE_ATOM),
            ],
        ];
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);
        self::assertResponseIsSuccessful();

        return [$crawler, $project];
    }

    /** Project management is the owner's today, so a checker stands in for a member who cannot manage. */
    private function denyManage(): void
    {
        $container = static::getContainer();
        $tokens = $container->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $decisions = $container->get('security.access.decision_manager');
        self::assertInstanceOf(AccessDecisionManagerInterface::class, $decisions);
        $real = new AuthorizationChecker($tokens, $decisions);

        $container->set('security.authorization_checker', new readonly class($real) implements AuthorizationCheckerInterface {
            public function __construct(
                private AuthorizationCheckerInterface $real,
            ) {
            }

            #[\Override]
            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return ProjectVoter::MANAGE !== $attribute && $this->real->isGranted($attribute, $subject, $accessDecision);
            }
        });
    }
}
