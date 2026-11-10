<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service\Dev;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Answers the two picker calls for a preview token, so the picker page shows in
 * a worktree that has no GitHub sign-in. Every other call and every other token
 * goes to GitHub as before.
 */
#[AsDecorator(decorates: 'github.api_client')]
#[When('dev')]
final readonly class DevGitHubPickerClient implements HttpClientInterface
{
    public const string TOKEN_LISTING = 'dev-picker-listing';
    public const string TOKEN_NONE = 'dev-picker-none';

    public const int FREE_REPOSITORY = 9101;
    public const int HELD_REPOSITORY = 9102;
    public const int OTHER_PROJECT_REPOSITORY = 9103;

    public function __construct(
        #[AutowireDecorated]
        private HttpClientInterface $inner,
    ) {
    }

    /** @param array<string, mixed> $options */
    #[\Override]
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $authorization = $options['headers']['Authorization'] ?? null;
        $token = \is_string($authorization) ? substr($authorization, \strlen('Bearer ')) : null;

        if ('GET' === $method && \in_array($token, [self::TOKEN_LISTING, self::TOKEN_NONE], true)) {
            $installations = self::TOKEN_LISTING === $token ? [
                ['id' => 4242, 'account' => ['login' => 'acme', 'id' => 424242], 'repository_selection' => 'selected', 'html_url' => 'https://github.com/organizations/acme/settings/installations/4242'],
                ['id' => 4343, 'account' => ['login' => 'globex', 'id' => 434343], 'repository_selection' => 'selected', 'html_url' => 'https://github.com/organizations/globex/settings/installations/4343'],
            ] : [];

            if ('/user/installations' === $url) {
                return $this->json($url, ['installations' => $installations]);
            }

            if ('/user/installations/4242/repositories' === $url) {
                return $this->json($url, ['repositories' => [
                    ['id' => self::FREE_REPOSITORY, 'full_name' => 'acme/free-app'],
                    ['id' => self::HELD_REPOSITORY, 'full_name' => 'acme/held-app'],
                    ['id' => self::OTHER_PROJECT_REPOSITORY, 'full_name' => 'acme/elsewhere-app'],
                ]]);
            }

            if ('/user/installations/4343/repositories' === $url) {
                return $this->json($url, ['repositories' => [['id' => 9201, 'full_name' => 'globex/web']]]);
            }
        }

        return $this->inner->request($method, $url, $options);
    }

    #[\Override]
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    /** @param array<string, mixed> $options */
    #[\Override]
    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options));
    }

    /** @param array<string, mixed> $body */
    private function json(string $path, array $body): ResponseInterface
    {
        $response = new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);

        return new MockHttpClient($response)->request('GET', 'https://api.github.com'.$path);
    }
}
