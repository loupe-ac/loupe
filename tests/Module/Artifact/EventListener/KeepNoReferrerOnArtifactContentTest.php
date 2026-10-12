<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\EventListener;

use App\Module\Artifact\EventListener\KeepNoReferrerOnArtifactContent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class KeepNoReferrerOnArtifactContentTest extends TestCase
{
    public function test_it_restores_no_referrer_on_the_content_route_after_another_listener_replaced_it(): void
    {
        $response = new Response('x', Response::HTTP_OK, ['Referrer-Policy' => 'strict-origin-when-cross-origin']);

        $this->dispatch('app_artifact_content', $response);

        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function test_it_restores_no_referrer_on_the_content_domain_route_too(): void
    {
        $response = new Response('x', Response::HTTP_OK, ['Referrer-Policy' => 'strict-origin-when-cross-origin']);

        $this->dispatch('app_artifact_content_host', $response);

        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function test_it_leaves_every_other_route_alone(): void
    {
        $response = new Response('x', Response::HTTP_OK, ['Referrer-Policy' => 'strict-origin-when-cross-origin']);

        $this->dispatch('app_project_artifacts', $response);

        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
    }

    private function dispatch(string $route, Response $response): void
    {
        $request = new Request(attributes: ['_route' => $route]);
        (new KeepNoReferrerOnArtifactContent())(new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));
    }
}
