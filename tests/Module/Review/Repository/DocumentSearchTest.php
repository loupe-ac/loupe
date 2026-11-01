<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Repository;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\RenameDocumentCommand;
use App\Module\Review\Command\RenameDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Series;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentSearchTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private DocumentRepository $documents;

    private CreateDocumentHandler $create;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->documents = self::getContainer()->get(DocumentRepository::class);
        $this->create = self::getContainer()->get(CreateDocumentHandler::class);
    }

    private function project(): Project
    {
        $owner = new User(
            fullName: 'Search Owner',
            email: 'search-'.uniqid().'@example.com',
            password: 'hashed-password-placeholder',
        );
        $this->em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    /**
     * @param list<string> $tagNames
     */
    private function document(Project $project, string $title, string $markdown, array $tagNames = [], ?string $seriesName = null, ?int $seriesOrdinal = null): Document
    {
        return ($this->create)(new CreateDocumentCommand(
            $project,
            $title,
            $markdown,
            tagNames: $tagNames,
            seriesName: $seriesName,
            seriesOrdinal: $seriesOrdinal,
        ));
    }

    /**
     * @param iterable<Document> $paginator
     *
     * @return list<string>
     */
    private function titles(iterable $paginator): array
    {
        return array_map(static fn (Document $d): string => $d->title, iterator_to_array($paginator, false));
    }

    public function test_search_matches_the_body_and_ignores_documents_that_do_not(): void
    {
        $project = $this->project();
        $this->document($project, 'Rate limits', '# Rate limits'."\n\n".'A leaky bucket per token.');
        $this->document($project, 'Onboarding', '# Onboarding'."\n\n".'The first-run wizard.');

        self::assertSame(
            ['Rate limits'],
            $this->titles($this->documents->findPaginatedByProject($project, 1, 20, search: 'bucket')),
        );
    }

    public function test_a_title_hit_outranks_a_body_mention(): void
    {
        $project = $this->project();
        // The title hit is created first, so recency would put it last and the
        // assertion below only holds if the title match drives the order.
        $this->document($project, 'The scheduler', '# The scheduler'."\n\n".'Ticks on a cron grid.');
        $this->document($project, 'Deployment notes', 'The scheduler runs hourly.'."\n\n".'Mentioned once.');

        self::assertSame(
            ['The scheduler', 'Deployment notes'],
            $this->titles($this->documents->findPaginatedByProject($project, 1, 20, search: 'scheduler')),
        );
    }

    public function test_search_reads_the_current_version_not_the_history(): void
    {
        $project = $this->project();
        $document = $this->document($project, 'Storage', '# Storage'."\n\n".'Backed by memcached.');

        (self::getContainer()->get(ReviseDocumentHandler::class))(
            new ReviseDocumentCommand($document, '# Storage'."\n\n".'Backed by valkey.', 'Swapped the backend'),
        );

        self::assertSame([], $this->titles($this->documents->findPaginatedByProject($project, 1, 20, search: 'memcached')));
        self::assertSame(
            ['Storage'],
            $this->titles($this->documents->findPaginatedByProject($project, 1, 20, search: 'valkey')),
        );
    }

    public function test_renaming_a_document_changes_the_title_that_search_finds(): void
    {
        $project = $this->project();
        // The body deliberately shares no word with either title, so the
        // assertions below can only be about the title.
        $document = $this->document($project, 'Untitled draft', 'Some prose about caching.');

        (self::getContainer()->get(RenameDocumentHandler::class))(
            new RenameDocumentCommand($document, 'Webhook retries'),
        );

        self::assertSame([], $this->titles($this->documents->findPaginatedByProject($project, 1, 20, search: 'untitled')));
        self::assertSame(
            ['Webhook retries'],
            $this->titles($this->documents->findPaginatedByProject($project, 1, 20, search: 'webhook')),
        );
    }

    public function test_punctuation_in_the_search_box_does_not_raise(): void
    {
        $project = $this->project();
        $this->document($project, 'Parser', '# Parser'."\n\n".'Handles input.');

        self::assertSame(
            [],
            $this->titles($this->documents->findPaginatedByProject($project, 1, 20, search: 'foo & | ! ( bar')),
        );
    }

    public function test_a_query_of_punctuation_only_matches_no_document(): void
    {
        $project = $this->project();
        $this->document($project, 'Parser', '# Parser'."\n\n".'Handles input.');

        // The guard: a query with a word finds the document, so the empty
        // answers below come from the query and not from an empty project.
        self::assertSame(['Parser'], $this->titles($this->documents->findPaginatedByProject($project, 1, 20, search: 'pars')));
        foreach (['!!!', '#', '"', '-', '- -'] as $query) {
            self::assertSame([], $this->titles($this->documents->findPaginatedByProject($project, 1, 20, search: $query)), $query);
        }
    }

    public function test_a_piece_from_inside_a_word_finds_the_document_in_title_and_body(): void
    {
        $project = $this->project();
        $this->document($project, 'Pagination', 'Nothing relevant.');
        $this->document($project, 'Other', 'The scheduler pages through rows.');
        $this->document($project, 'Unrelated', 'Plain text.');

        self::assertSame(
            ['Pagination'],
            $this->titles($this->documents->findPaginatedByProject($project, 1, 20, search: 'AGIN')),
            'a piece inside "Pagination" matches, ignoring case',
        );
        self::assertSame(['Other'], $this->titles($this->documents->findPaginatedByProject($project, 1, 20, search: 'ages thr')));
    }

    public function test_quotes_minus_and_or_work_on_documents(): void
    {
        $project = $this->project();
        $this->document($project, 'Board search', 'Finds cards on the board.');
        $this->document($project, 'Search board', 'The board is searched.');
        $this->document($project, 'Backlog search', 'Search the backlog.');

        self::assertSame(['Board search'], $this->titles($this->documents->findPaginatedByProject($project, 1, 20, search: '"board search"')));
        self::assertEqualsCanonicalizing(
            ['Board search', 'Search board'],
            $this->titles($this->documents->findPaginatedByProject($project, 1, 20, search: 'search -backlog')),
            'a minus excludes the documents that hold the word',
        );
        self::assertEqualsCanonicalizing(
            ['Board search', 'Backlog search'],
            $this->titles($this->documents->findPaginatedByProject($project, 1, 20, search: 'finds or backlog')),
        );
        self::assertSame(
            ['Search board'],
            $this->titles($this->documents->findPaginatedByProject($project, 1, 20, search: '-backlog "search board"')),
            'a query of an exclusion and a phrase combines both',
        );
    }

    public function test_status_and_tag_filters_narrow_the_list(): void
    {
        $project = $this->project();
        $approved = $this->document($project, 'Signed off', '# Signed off', ['design']);
        $approved->status = DocumentStatus::Approved;
        $this->document($project, 'Still going', '# Still going', ['design']);
        $this->document($project, 'Untagged', '# Untagged');
        $this->em->flush();

        self::assertSame(
            ['Signed off'],
            $this->titles($this->documents->findPaginatedByProject($project, 1, 20, status: DocumentStatus::Approved)),
        );
        self::assertEqualsCanonicalizing(
            ['Signed off', 'Still going'],
            $this->titles($this->documents->findPaginatedByProject($project, 1, 20, tagName: Tag::normalizeName('Design'))),
        );
    }

    public function test_search_and_filters_combine(): void
    {
        $project = $this->project();
        $this->document($project, 'Billing webhooks', '# Billing webhooks', ['payments']);
        $this->document($project, 'Billing overview', '# Billing overview', ['docs']);

        self::assertSame(
            ['Billing webhooks'],
            $this->titles($this->documents->findPaginatedByProject(
                $project,
                1,
                20,
                search: 'billing',
                tagName: 'payments',
            )),
        );
    }

    public function test_archived_documents_stay_out_of_search_results(): void
    {
        $project = $this->project();
        $archived = $this->document($project, 'Retired spec', '# Retired spec');
        $archived->archivedAt = new \DateTimeImmutable();
        $this->em->flush();

        self::assertSame([], $this->titles($this->documents->findPaginatedByProject($project, 1, 20, search: 'retired')));
        self::assertSame(
            ['Retired spec'],
            $this->titles($this->documents->findPaginatedByProject($project, 1, 20, true, search: 'retired')),
        );
    }

    public function test_a_ranked_page_counts_only_the_matches(): void
    {
        $project = $this->project();
        for ($i = 0; $i < 3; ++$i) {
            $this->document($project, 'Matching '.$i, '# Kafka topic '.$i);
        }
        $this->document($project, 'Unrelated', '# Something else entirely');

        self::assertCount(3, $this->documents->findPaginatedByProject($project, 1, 20, search: 'kafka'));
    }

    /**
     * A series is an ordered set, so its own numbering has to outrank the title
     * match and recency once the reader has asked for it. Part one is created
     * first, so recency ordering would return the two the other way round.
     */
    public function test_search_within_a_series_orders_by_the_series_numbering(): void
    {
        $project = $this->project();
        $this->document($project, 'Part one', 'The storage uses postgres.', seriesName: 'Guide', seriesOrdinal: 1);
        $this->document($project, 'Part two', 'The storage uses postgres.', seriesName: 'Guide', seriesOrdinal: 2);
        $this->document($project, 'Loose note', 'Another note about postgres.');

        self::assertSame(
            ['Part one', 'Part two'],
            $this->titles($this->documents->findPaginatedByProject(
                $project,
                1,
                20,
                search: 'postgres',
                seriesName: Series::normalizeName('Guide'),
            )),
            'the series numbering orders the matches, and a match outside the series is filtered out',
        );
    }

    public function test_searching_a_project_with_no_documents_returns_nothing(): void
    {
        // The query builds one branch per language the project holds. A project
        // that holds none still has to produce valid DQL.
        $project = $this->project();

        self::assertSame([], $this->titles($this->documents->findPaginatedByProject($project, 1, 20, search: 'anything')));
    }
}
