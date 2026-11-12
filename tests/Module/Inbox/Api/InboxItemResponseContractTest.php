<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Api;

use App\Module\Inbox\Controller\Api\InboxItemResponse;
use App\Module\Inbox\Entity\InboxCloser;
use OpenApi\Attributes as OA;
use PHPUnit\Framework\TestCase;

final class InboxItemResponseContractTest extends TestCase
{
    public function test_closed_by_lists_every_closer_value(): void
    {
        $parameter = new \ReflectionClass(InboxItemResponse::class)->getConstructor()?->getParameters();
        self::assertNotNull($parameter);

        $enum = null;
        foreach ($parameter as $candidate) {
            if ('closedBy' === $candidate->getName()) {
                $enum = $candidate->getAttributes(OA\Property::class)[0]->newInstance()->enum;
            }
        }

        self::assertEqualsCanonicalizing(array_map(static fn (InboxCloser $closer): string => $closer->value, InboxCloser::cases()), $enum);
    }
}
