<?php

declare(strict_types=1);

namespace App\Tests\Api\EventListener;

use App\Api\EventListener\ApplyApiBudgetOnRequest;
use App\Api\EventListener\ClaimIdempotencyKeyOnRequest;
use App\Api\EventListener\RefuseFlaggedApiWhenSwitchedOff;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Review\Controller\Api\SetDocumentHighlightsController;
use App\Module\Review\Mcp\DocumentHighlightTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

final class RefuseFlaggedApiWhenSwitchedOffTest extends KernelTestCase
{
    private const string INBOX_NAMESPACE = 'App\Module\Inbox\Controller\Api\\';

    public function test_a_route_name_maps_to_the_flag_of_its_feature(): void
    {
        self::assertSame(ArtifactInstallFlags::FLAG_ENABLED, RefuseFlaggedApiWhenSwitchedOff::flagOf('api_artifact_list'));
        self::assertSame(InboxInstallFlags::FLAG_INBOX_ENABLED, RefuseFlaggedApiWhenSwitchedOff::flagOf('api_inbox_item_show'));
        self::assertSame(InboxInstallFlags::FLAG_INBOX_ENABLED, RefuseFlaggedApiWhenSwitchedOff::flagOf('api_project_inbox_ask_check'));
        self::assertSame(DocumentHighlightTool::FLAG, RefuseFlaggedApiWhenSwitchedOff::flagOf('api_document_highlights'));
        self::assertNull(RefuseFlaggedApiWhenSwitchedOff::flagOf('api_card_list'));
        self::assertNull(RefuseFlaggedApiWhenSwitchedOff::flagOf('api_document_highlights_other_route_is_not_gated_by_name_prefix'));
    }

    public function test_every_route_of_an_inbox_api_controller_is_gated_by_the_inbox_flag(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        $seen = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (\is_string($controller) && str_starts_with($controller, self::INBOX_NAMESPACE)) {
                self::assertSame(InboxInstallFlags::FLAG_INBOX_ENABLED, RefuseFlaggedApiWhenSwitchedOff::flagOf($name), \sprintf('The route %s of %s escapes the inbox gate.', $name, $controller));
                $seen[] = $name;
            }
        }

        self::assertContains('api_project_inbox_ask_check', $seen);
        self::assertContains('api_inbox_item_show', $seen);
        self::assertCount(8, $seen);
    }

    public function test_the_highlights_route_carries_the_name_that_the_gate_maps(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        $route = $router->getRouteCollection()->get('api_document_highlights');

        self::assertNotNull($route);
        self::assertSame(SetDocumentHighlightsController::class, $route->getDefault('_controller'));
        self::assertSame(['PUT'], $route->getMethods());
    }

    public function test_the_gate_runs_after_the_firewall_and_before_the_budget_and_the_idempotency_claim(): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $priorityOf = static function (string $class) use ($dispatcher): int {
            foreach ($dispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
                if (\is_array($listener) && $listener[0] instanceof $class) {
                    return $dispatcher->getListenerPriority(KernelEvents::REQUEST, $listener) ?? throw new \LogicException('No priority.');
                }
            }

            throw new \LogicException(\sprintf('%s is not a request listener.', $class));
        };

        $gate = $priorityOf(RefuseFlaggedApiWhenSwitchedOff::class);
        self::assertSame(5, $gate);
        self::assertLessThan(8, $gate, 'the firewall runs first, so a caller with no credential still gets 401');
        self::assertGreaterThan($priorityOf(ApplyApiBudgetOnRequest::class), $gate);
        self::assertGreaterThan($priorityOf(ClaimIdempotencyKeyOnRequest::class), $gate);
    }
}
