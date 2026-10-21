<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewScreenshot;
use App\Tests\Support\TinyImage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ShowCardFeedbackScreenshotControllerTest extends WebTestCase
{
    use BoardScenario;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_the_owner_reads_the_screenshot_with_hardened_headers(): void
    {
        $owner = $this->user($this->em, 'screenshot-owner@example.com');
        $project = $this->project($this->em, $owner);
        $png = TinyImage::png(3, 2);
        $link = $this->note($project, $png);
        $this->client->loginUser($owner);

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/board/feedback/'.$link->id.'/screenshot');

        self::assertResponseStatusCodeSame(200);
        $response = $this->client->getResponse();
        self::assertSame($png, $response->getContent());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_note_without_a_screenshot_is_not_found(): void
    {
        $owner = $this->user($this->em, 'screenshot-none@example.com');
        $project = $this->project($this->em, $owner);
        $link = $this->note($project, null);
        $this->client->loginUser($owner);

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/board/feedback/'.$link->id.'/screenshot');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_another_user_is_refused(): void
    {
        $owner = $this->user($this->em, 'screenshot-owner2@example.com');
        $stranger = $this->user($this->em, 'screenshot-stranger@example.com');
        $link = $this->note($this->project($this->em, $owner), TinyImage::png());
        $this->client->loginUser($stranger);

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/board/feedback/'.$link->id.'/screenshot');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_note_of_another_project_is_refused_to_the_card_owner(): void
    {
        $owner = $this->user($this->em, 'screenshot-cross@example.com');
        $project = $this->project($this->em, $owner);
        $otherProject = $this->project($this->em, $owner, 'Other project');
        $link = $this->note($project, TinyImage::png(), $otherProject);
        $this->client->loginUser($owner);

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/board/feedback/'.$link->id.'/screenshot');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_signed_out_visitor_is_sent_to_sign_in(): void
    {
        $owner = $this->user($this->em, 'screenshot-anon@example.com');
        $link = $this->note($this->project($this->em, $owner), TinyImage::png());

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/board/feedback/'.$link->id.'/screenshot');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function note(Project $project, ?string $png, ?Project $noteProject = null): CardSiteReviewComment
    {
        $card = $this->card($this->em, $project, 'Fix the screen');
        $comment = new SiteReviewComment($noteProject ?? $project, 0, 'Looks off', 'https://app.example/page');
        $link = new CardSiteReviewComment($card, $comment);
        $this->em->persist($comment);
        $this->em->persist($link);
        if (null !== $png) {
            $this->em->persist(new SiteReviewScreenshot($comment, $noteProject ?? $project, 'image/png', $png, \strlen($png), 3, 2));
        }
        $this->em->flush();
        $this->em->clear();

        return $link;
    }
}
