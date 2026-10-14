<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Controller;

use App\Module\Account\Service\RegistrationGate;
use App\Tests\Support\InstalledInstance;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final class SitemapControllerTest extends WebTestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        $this->setBlogProxyHost('');

        parent::tearDown();
    }

    /** @return list<string> */
    private function locations(KernelBrowser $client): array
    {
        $client->request(Request::METHOD_GET, '/sitemap.xml', server: ['HTTP_HOST' => 'evil.example']);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('application/xml', (string) $client->getResponse()->headers->get('Content-Type'));
        $xml = simplexml_load_string((string) $client->getResponse()->getContent());
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);

        return array_values(array_map(strval(...), $xml->xpath('//*[local-name()="loc"]') ?: []));
    }

    private function base(): string
    {
        $appUrl = static::getContainer()->getParameter('app.url');
        self::assertIsString($appUrl);

        return rtrim($appUrl, '/');
    }

    public function test_it_lists_the_home_and_legal_pages_for_a_visitor_with_no_session(): void
    {
        $client = static::createClient();

        $locations = $this->locations($client);

        $base = $this->base();
        foreach (['/', '/privacy', '/terms', '/ai-policy'] as $path) {
            self::assertContains($base.$path, $locations);
        }
        self::assertNotContains($base.'/login', $locations);
        foreach ($locations as $location) {
            self::assertStringNotContainsString('evil.example', $location);
        }
    }

    public function test_it_leaves_the_waitlist_out_while_the_install_wizard_is_open(): void
    {
        $client = static::createClient();

        self::assertNotContains($this->base().'/waitlist', $this->locations($client));
    }

    public function test_it_leaves_the_waitlist_out_while_sign_up_is_open(): void
    {
        $client = static::createClient();
        InstalledInstance::ensure($client->getContainer());

        self::assertNotContains($this->base().'/waitlist', $this->locations($client));
    }

    public function test_it_lists_the_waitlist_once_the_registration_cap_is_reached(): void
    {
        $client = static::createClient();
        InstalledInstance::ensure($client->getContainer());
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist(new FeatureFlag(name: RegistrationGate::CAP_FLAG, type: FeatureFlagType::Int, value: 1));
        $em->flush();

        self::assertContains($this->base().'/waitlist', $this->locations($client));
    }

    public function test_it_lists_the_blog_when_a_blog_host_is_set(): void
    {
        $this->setBlogProxyHost('blog.example.com');
        $client = static::createClient();

        $locations = $this->locations($client);

        self::assertContains($this->base().'/blog', $locations);
        self::assertStringNotContainsString('blog.example.com', (string) $client->getResponse()->getContent());
    }

    public function test_it_leaves_the_blog_out_when_no_blog_host_is_set(): void
    {
        $this->setBlogProxyHost('');
        $client = static::createClient();

        $locations = $this->locations($client);

        self::assertContains($this->base().'/privacy', $locations);
        self::assertNotContains($this->base().'/blog', $locations);
    }

    private function setBlogProxyHost(string $host): void
    {
        $_ENV['BLOG_PROXY_HOST'] = $_SERVER['BLOG_PROXY_HOST'] = $host;
    }
}
