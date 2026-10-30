<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Exception\DomainErrors;
use App\Module\Inbox\Command\AnswerInboxItemHandler;
use App\Module\Inbox\Command\DeclineInboxItemHandler;
use App\Module\Inbox\Command\MarkInboxItemDoneHandler;
use App\Module\Inbox\Mcp\InboxToolErrorMessages;
use App\Module\Inbox\Service\InboxItemCloser;
use App\Module\Inbox\Service\SessionPromptLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final class InboxToolErrorMessagesTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function responseKeys(): iterable
    {
        yield 'not a question' => [AnswerInboxItemHandler::NOT_A_QUESTION, 'Only a question takes an answer.'];
        yield 'unknown option' => [AnswerInboxItemHandler::UNKNOWN_OPTION, 'Each selected option must be an index into the options of the item, counting from 0.'];
        yield 'one option' => [AnswerInboxItemHandler::ONE_OPTION, 'This question takes one option.'];
        yield 'no free text' => [AnswerInboxItemHandler::NO_FREE_TEXT, 'This question takes no free text.'];
        yield 'empty answer' => [AnswerInboxItemHandler::EMPTY, 'Pass at least one selected option or an answer text.'];
        yield 'not a todo' => [MarkInboxItemDoneHandler::NOT_A_TO_DO, 'Only a todo can be marked done.'];
        yield 'workflow not declinable' => [DeclineInboxItemHandler::WORKFLOW_NOT_DECLINABLE, 'A workflow question cannot be declined. Answer it with inbox_answer.'];
        yield 'final' => [InboxItemCloser::ERROR_FINAL, 'The item is closed, so its response is final.'];
        yield 'notice' => [InboxItemCloser::ERROR_NOTICE, 'A notice takes no response.'];
        yield 'review on the page' => [InboxToolErrorMessages::REVIEW_ON_PAGE, 'A review verdict stays on the inbox page. Ask the owner to give it there, or call document_verdict_submit when the owner gave it in the chat.'];
    }

    #[DataProvider('responseKeys')]
    public function test_a_response_key_reads_as_a_sentence_for_the_agent(string $key, string $sentence): void
    {
        $exception = $this->messages()->forAgent(new DomainErrors(['selectedOptions' => $key]));

        self::assertSame('selectedOptions: '.$sentence, $exception->getMessage());
    }

    public function test_an_unmapped_key_gets_the_generic_message(): void
    {
        $exception = $this->messages()->forAgent(new DomainErrors(['item' => 'inbox.no.such.key']));

        self::assertSame('item: '.InboxToolErrorMessages::UNMAPPED, $exception->getMessage());
    }

    private function messages(): InboxToolErrorMessages
    {
        return new InboxToolErrorMessages(new SessionPromptLimit($this->createStub(FeatureFlagService::class)));
    }
}
