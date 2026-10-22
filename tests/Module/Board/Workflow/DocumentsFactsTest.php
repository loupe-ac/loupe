<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Workflow\DocumentsFacts;
use App\Module\Board\Workflow\ParentDocumentsFacts;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\TestCase;

final class DocumentsFactsTest extends TestCase
{
    public function test_the_facts_list_the_card_documents_and_bind_one_of_them(): void
    {
        $documents = new DocumentsFacts([new DocumentFacts(['a'], 'in-review', 'one'), new DocumentFacts(['a'], 'in-review', 'two')]);
        $facts = FactsMother::facts(provided: [DocumentsFacts::class => $documents, ParentDocumentsFacts::class => new ParentDocumentsFacts([new DocumentFacts(['a'], 'approved', 'parent')])]);

        $bound = $facts->withDocument($documents->documents[1]);

        self::assertSame(['one', 'two'], array_map(static fn (DocumentFacts $document): string => $document->id, $facts->documents()), 'the parent documents are not the card documents');
        self::assertSame($facts->documents(), $bound->documents(), 'a bound document hides no document from a rule');
        self::assertSame([true, true], [$facts->readsDocument('one'), $facts->readsDocument('two')]);
        self::assertSame([false, true], [$bound->readsDocument('one'), $bound->readsDocument('two')]);
        self::assertTrue($bound->withPullRequest(null)->withProvided([])->readsDocument('two') && !$bound->withPullRequest(null)->readsDocument('one'), 'a copy keeps the bound document');
    }
}
