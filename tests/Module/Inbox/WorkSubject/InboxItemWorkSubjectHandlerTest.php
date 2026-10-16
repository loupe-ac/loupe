<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\WorkSubject;

use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Inbox\WorkSubject\InboxItemWorkSubjectHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InboxItemWorkSubjectHandlerTest extends KernelTestCase
{
    public function test_the_subject_type_is_the_inbox_item(): void
    {
        self::assertSame('inbox-item', InboxItemWorkSubjectHandler::subjectType());
    }

    public function test_the_container_registers_it_so_a_request_may_name_an_inbox_item(): void
    {
        self::bootKernel();
        $handlers = self::getContainer()->get(WorkSubjectHandlers::class);
        self::assertInstanceOf(WorkSubjectHandlers::class, $handlers);

        self::assertTrue($handlers->has('inbox-item'));
        self::assertInstanceOf(InboxItemWorkSubjectHandler::class, $handlers->for('inbox-item'));
    }
}
