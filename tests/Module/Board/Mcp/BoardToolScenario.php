<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Entity\SiteReviewScreenshot;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\TinyImage;

/**
 * KernelTestCase helper for the board MCP tools.
 *
 * Requires an `$em` EntityManagerInterface property on the using class, the
 * same contract McpTokenScenario has.
 */
trait BoardToolScenario
{
    use BoardColumnFixtures;

    private function makeProject(string $label): Project
    {
        $owner = new User(fullName: 'Riley', email: $label.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);

        $project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->em->flush();

        return $project;
    }

    /**
     * A note on the card, made `$position` seconds after the first one, so a
     * higher position is a newer note. A screenshot is a PNG as wide as the
     * position plus one.
     */
    private function feedbackNote(Project $project, Card $card, int $position, bool $screenshot = true, SiteReviewCommentStatus $status = SiteReviewCommentStatus::Pending): SiteReviewComment
    {
        $comment = new SiteReviewComment($project, $position, 'Note '.$position, 'https://app.example/page', createdAt: new \DateTimeImmutable('2026-10-01 09:00:00')->modify('+'.$position.' seconds'));
        $comment->status = $status;
        $this->em->persist($comment);
        $this->em->persist(new CardSiteReviewComment($card, $comment));

        if ($screenshot) {
            $png = TinyImage::png($position + 1, 1);
            $this->em->persist(new SiteReviewScreenshot($comment, $project, 'image/png', $png, \strlen($png), $position + 1, 1));
        }

        return $comment;
    }

    /** The base64 of the screenshot that feedbackNote() gives the note at this position. */
    private function feedbackPng(int $position): string
    {
        return base64_encode(TinyImage::png($position + 1, 1));
    }
}
