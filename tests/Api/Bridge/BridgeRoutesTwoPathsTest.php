<?php

declare(strict_types=1);

namespace App\Tests\Api\Bridge;

use App\Module\Artifact\Controller\Api\StoreArtifactVersionController;
use App\Module\Board\Controller\Api\ListProjectColumnsController;
use App\Module\Board\Controller\Api\ShowProjectCardController;
use App\Module\Bridge\Controller\Api\AcknowledgeBridgeCommandController;
use App\Module\Bridge\Controller\Api\AppendWorkerRunOutputController;
use App\Module\Bridge\Controller\Api\ClaimWorkRequestController;
use App\Module\Bridge\Controller\Api\CloseInteractiveSessionController;
use App\Module\Bridge\Controller\Api\ListCardHoldsController;
use App\Module\Bridge\Controller\Api\RecordBridgeHeartbeatController;
use App\Module\Bridge\Controller\Api\ReportBridgeRunsController;
use App\Module\Bridge\Controller\Api\ReportInteractiveLaunchController;
use App\Module\Bridge\Controller\Api\ReportSessionUsageController;
use App\Module\Bridge\Controller\Api\ReportToolCallsController;
use App\Module\Bridge\Controller\Api\ReportWorkerRunStateController;
use App\Module\Bridge\Controller\Api\ResolveExperimentPinController;
use App\Module\Bridge\Controller\Api\SettleWorkRequestController;
use App\Module\GitHub\Controller\Api\IssueGitTokenController;
use App\Module\Inbox\Controller\Api\CheckInboxAskController;
use App\Module\OAuth\Controller\Api\MintPreviewTokenController;
use App\Module\OAuth\Controller\Api\RevokePreviewTokenController;
use App\Module\SiteReview\Controller\Api\ListSitesController;
use App\Module\SiteReview\Controller\Api\ShowEventReplayController;
use App\Module\SiteReview\Controller\Api\ShowEventsController;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every bridge controller answers on its old path and on its /api/v1/bridge
 * path. The new path keeps the route name, and the old path gets the
 * `api_legacy_` prefix.
 */
final class BridgeRoutesTwoPathsTest extends KernelTestCase
{
    private const array SAMPLE = [
        '{bridgeId}' => '0f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f',
        '{commandId}' => '1f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f',
        '{workRequestId}' => '2f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f',
        '{sessionId}' => 'session-1',
        '{handle}' => 'loupe',
        '{runId}' => '3f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f',
        '{cardId}' => '4f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f',
        '{askId}' => '5f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f',
        '{experiment}' => 'exp-1',
        '{label}' => 'preview',
    ];

    /** @return array<string, array{class-string, string, string, string}> */
    public static function routes(): array
    {
        return [
            'AcknowledgeBridgeCommandController' => [AcknowledgeBridgeCommandController::class, 'PUT', '/api/bridges/{bridgeId}/commands/{commandId}', '/api/v1/bridge/bridges/{bridgeId}/commands/{commandId}'],
            'RecordBridgeHeartbeatController' => [RecordBridgeHeartbeatController::class, 'PUT', '/api/bridges/{bridgeId}/heartbeat', '/api/v1/bridge/bridges/{bridgeId}/heartbeat'],
            'ReportBridgeRunsController' => [ReportBridgeRunsController::class, 'PUT', '/api/bridges/{bridgeId}/runs', '/api/v1/bridge/bridges/{bridgeId}/runs'],
            'ClaimWorkRequestController' => [ClaimWorkRequestController::class, 'POST', '/api/bridges/{bridgeId}/work-requests/{workRequestId}/claim', '/api/v1/bridge/bridges/{bridgeId}/work-requests/{workRequestId}/claim'],
            'SettleWorkRequestController' => [SettleWorkRequestController::class, 'PUT', '/api/bridges/{bridgeId}/work-requests/{workRequestId}/result', '/api/v1/bridge/bridges/{bridgeId}/work-requests/{workRequestId}/result'],
            'ListCardHoldsController' => [ListCardHoldsController::class, 'GET', '/api/card-holds', '/api/v1/bridge/card-holds'],
            'ShowEventsController' => [ShowEventsController::class, 'GET', '/api/events', '/api/v1/bridge/events'],
            'ShowEventReplayController' => [ShowEventReplayController::class, 'GET', '/api/events/replay', '/api/v1/bridge/events/replay'],
            'ListSitesController' => [ListSitesController::class, 'GET', '/api/projects', '/api/v1/bridge/projects'],
            'ShowProjectCardController' => [ShowProjectCardController::class, 'GET', '/api/projects/{handle}/board/cards/{cardId}', '/api/v1/bridge/projects/{handle}/cards/{cardId}'],
            'ListProjectColumnsController' => [ListProjectColumnsController::class, 'GET', '/api/projects/{handle}/board/columns', '/api/v1/bridge/projects/{handle}/columns'],
            'CloseInteractiveSessionController' => [CloseInteractiveSessionController::class, 'POST', '/api/interactive-sessions/{sessionId}/close', '/api/v1/bridge/interactive-sessions/{sessionId}/close'],
            'ReportWorkerRunStateController' => [ReportWorkerRunStateController::class, 'PUT', '/api/projects/{handle}/worker-runs/{runId}', '/api/v1/bridge/projects/{handle}/worker-runs/{runId}'],
            'ReportSessionUsageController' => [ReportSessionUsageController::class, 'PUT', '/api/projects/{handle}/worker-runs/sessions/{sessionId}/usage', '/api/v1/bridge/projects/{handle}/worker-runs/sessions/{sessionId}/usage'],
            'ReportToolCallsController' => [ReportToolCallsController::class, 'PUT', '/api/projects/{handle}/worker-runs/{runId}/tool-calls', '/api/v1/bridge/projects/{handle}/worker-runs/{runId}/tool-calls'],
            'AppendWorkerRunOutputController' => [AppendWorkerRunOutputController::class, 'PUT', '/api/projects/{handle}/worker-runs/{runId}/output', '/api/v1/bridge/projects/{handle}/worker-runs/{runId}/output'],
            'ReportInteractiveLaunchController' => [ReportInteractiveLaunchController::class, 'PUT', '/api/projects/{handle}/interactive-runs/{sessionId}', '/api/v1/bridge/projects/{handle}/interactive-runs/{sessionId}'],
            'ResolveExperimentPinController' => [ResolveExperimentPinController::class, 'PUT', '/api/projects/{handle}/experiments/{experiment}/pins/{cardId}', '/api/v1/bridge/projects/{handle}/experiments/{experiment}/pins/{cardId}'],
            'CheckInboxAskController' => [CheckInboxAskController::class, 'GET', '/api/projects/{handle}/inbox/asks/{askId}', '/api/v1/bridge/projects/{handle}/inbox/asks/{askId}'],
            'IssueGitTokenController' => [IssueGitTokenController::class, 'POST', '/api/projects/{handle}/git-token', '/api/v1/bridge/projects/{handle}/git-token'],
            'MintPreviewTokenController' => [MintPreviewTokenController::class, 'POST', '/api/projects/{handle}/preview-tokens', '/api/v1/bridge/projects/{handle}/preview-tokens'],
            'RevokePreviewTokenController' => [RevokePreviewTokenController::class, 'DELETE', '/api/projects/{handle}/preview-tokens/{label}', '/api/v1/bridge/projects/{handle}/preview-tokens/{label}'],
            'StoreArtifactVersionController' => [StoreArtifactVersionController::class, 'POST', '/api/projects/{handle}/artifacts', '/api/v1/bridge/projects/{handle}/artifacts'],
        ];
    }

    /** @param class-string $controller */
    #[DataProvider('routes')]
    public function test_the_old_and_the_new_path_resolve_to_one_controller(string $controller, string $method, string $oldPath, string $newPath): void
    {
        $new = $this->match($newPath, $method);
        $old = $this->match($oldPath, $method);

        self::assertSame($controller, $new['_controller']);
        self::assertSame($controller, $old['_controller']);
        self::assertStringStartsWith('api_', (string) $new['_route']);
        self::assertStringStartsWith('api_legacy_', (string) $old['_route']);
        self::assertSame('api_legacy_'.substr((string) $new['_route'], \strlen('api_')), $old['_route']);
    }

    public function test_every_bridge_controller_route_is_in_the_table(): void
    {
        $router = static::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        $controllers = array_column(self::routes(), 0);

        $found = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if (str_starts_with($route->getPath(), '/api/v1/bridge/')) {
                $found[$name] = $route->getDefault('_controller');
                self::assertContains($route->getDefault('_controller'), $controllers, $name);
            }
        }
        self::assertCount(\count($controllers), $found);
    }

    /** @return array<string, mixed> */
    private function match(string $template, string $method): array
    {
        $router = static::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        $path = strtr($template, self::SAMPLE);

        return $router->matchRequest(Request::create($path, $method));
    }
}
