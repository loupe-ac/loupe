<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Service\InboxWaitSubjects;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxWaitSubjectsTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private InboxWaitSubjects $subjects;
    private Project $project;
    private InboxCardWatch $watch;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $subjects = self::getContainer()->get(InboxWaitSubjects::class);
        self::assertInstanceOf(InboxWaitSubjects::class, $subjects);
        $this->subjects = $subjects;

        $this->project = $this->project($em, $this->owner($em, 'wait-subjects'), 'wait-subjects');
        $item = new InboxItem(project: $this->project, number: 1, kind: InboxItemKind::Wait, title: '#4 Ship it', blocking: true, origin: InboxAskOrigin::Loupe);
        $this->watch = new InboxCardWatch($item, Uuid::v7(), 4);
    }

    public function test_a_preload_answers_a_stored_subject_and_a_missing_one_with_no_further_read(): void
    {
        $pullRequest = new ForgePullRequest($this->project, 'github', 'acme/widgets', 1);
        $this->em->persist($pullRequest);
        $this->em->flush();
        $stored = $pullRequest->id ?? throw new \LogicException('Stored.');
        $missingRun = Uuid::v7();
        $missingDocument = Uuid::v7();
        $missingPullRequest = Uuid::v7();

        $this->subjects->preload([
            new InboxCardWait($this->watch, 'a', InboxCardWaitType::PullRequest, 'label', pullRequestId: $stored, headSha: 'abc'),
            new InboxCardWait($this->watch, 'b', InboxCardWaitType::PullRequest, 'label', pullRequestId: $missingPullRequest, headSha: 'abc'),
            new InboxCardWait($this->watch, 'c', InboxCardWaitType::WorkerRun, 'label', runId: $missingRun),
            new InboxCardWait($this->watch, 'd', InboxCardWaitType::Document, 'label', documentId: $missingDocument, versionNumber: 1),
        ]);

        $reads = $this->countReads(function () use ($pullRequest, $stored, $missingRun, $missingDocument, $missingPullRequest): void {
            self::assertSame($pullRequest, $this->subjects->pullRequest($stored));
            self::assertNull($this->subjects->pullRequest($missingPullRequest));
            self::assertNull($this->subjects->run($missingRun));
            self::assertNull($this->subjects->document($missingDocument));
        });

        self::assertSame(0, $reads);
    }

    public function test_a_subject_that_was_not_preloaded_is_read_each_time(): void
    {
        $id = Uuid::v7();

        self::assertSame(1, $this->countReads(fn () => self::assertNull($this->subjects->document($id))));
        self::assertSame(1, $this->countReads(fn () => self::assertNull($this->subjects->document($id))));
    }

    public function test_reset_forgets_the_preload(): void
    {
        $id = Uuid::v7();
        $this->subjects->preload([new InboxCardWait($this->watch, 'a', InboxCardWaitType::WorkerRun, 'label', runId: $id)]);
        $this->subjects->reset();

        self::assertSame(1, $this->countReads(fn () => self::assertNull($this->subjects->run($id))));
    }

    private function countReads(callable $work): int
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();
        $work();

        $reads = 0;
        foreach ($holder->getData() as $queries) {
            foreach ($queries as $query) {
                if (str_starts_with((string) $query['sql'], 'SELECT')) {
                    ++$reads;
                }
            }
        }

        return $reads;
    }
}
