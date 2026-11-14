<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Account\EventListener\RedirectUnverifiedUserListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class RedirectUnverifiedUserListenerTest extends TestCase
{
    /** @return iterable<string, array{string, string, array<string, string>, bool}> */
    public static function requests(): iterable
    {
        yield 'a page load' => ['GET', '/github/app/setup?installation_id=7&setup_action=install', [], true];
        yield 'a post' => ['POST', '/projects', [], false];
        yield 'a turbo stream' => ['GET', '/projects', ['Accept' => 'text/vnd.turbo-stream.html'], false];
        yield 'the api' => ['GET', '/api/v1/bridge/projects', [], false];
        yield 'the profiler' => ['GET', '/_profiler/abc', [], false];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('requests')]
    public function test_it_saves_the_target_path_of_a_page_load_only(string $method, string $uri, array $headers, bool $saved): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create($uri, $method);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $request->setSession($session);

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new User('Riley', 'riley@example.com'), 'main', []));
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/register/check-email');

        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new RedirectUnverifiedUserListener($tokenStorage, $urls))($event);

        self::assertNotNull($event->getResponse());
        self::assertSame($saved ? 'http://localhost'.$uri : null, $session->get('_security.main.target_path'));
    }
}
