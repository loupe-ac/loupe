<?php

declare(strict_types=1);

namespace App\Tests\Docker;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** docker/prod/nginx-config.sh decides which server answers the site host, the docs and the blog. */
final class NginxConfigScriptTest extends TestCase
{
    private string $work = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir().'/nginx-config-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir([$this->work.'/snippets', $this->work.'/http.d', $this->work.'/docs']);
        file_put_contents($this->work.'/resolv.conf', "nameserver 10.0.0.2\n");
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->work);
    }

    public function test_a_split_instance_gets_a_site_server_with_the_docs_and_the_blog(): void
    {
        $this->stampedDocs();

        $this->generate(['SITE_HOST' => 'loupe.ac', 'DEFAULT_URI' => 'https://app.loupe.ac', 'BLOG_PROXY_HOST' => 'blog.example.com']);

        $site = $this->read('http.d/site.conf');
        self::assertStringContainsString('server_name loupe.ac;', $site);
        self::assertStringContainsString('include /etc/nginx/snippets/common.conf;', $site);
        self::assertStringContainsString('location = /landing/explainer.html', $site);
        self::assertStringContainsString('include /etc/nginx/snippets/site-*.conf;', $site);
        self::assertMatchesRegularExpression('#location / \{\s*rewrite \^ /index\.php last;\s*\}#', $site);
        self::assertStringNotContainsString('try_files', $site);
        self::assertStringContainsString('alias '.$this->work.'/docs/;', $this->read('snippets/site-docs.conf'));
        self::assertStringContainsString('proxy_set_header Cookie "";', $this->read('snippets/site-blog.conf'));
        self::assertFileDoesNotExist($this->work.'/snippets/app-docs.conf');
        self::assertFileDoesNotExist($this->work.'/snippets/app-blog.conf');
    }

    public function test_one_host_for_both_roles_gets_the_docs_and_the_blog_on_the_app_server(): void
    {
        $this->stampedDocs();

        $this->generate(['SITE_HOST' => 'app.example', 'DEFAULT_URI' => 'https://app.example:8443/', 'BLOG_PROXY_HOST' => 'blog.example.com']);

        self::assertFileDoesNotExist($this->work.'/http.d/site.conf');
        self::assertFileExists($this->work.'/snippets/app-docs.conf');
        self::assertFileExists($this->work.'/snippets/app-blog.conf');
    }

    public function test_an_instance_with_no_site_host_serves_no_docs(): void
    {
        $this->stampedDocs();

        $this->generate(['SITE_HOST' => '', 'DEFAULT_URI' => 'https://app.example', 'BLOG_PROXY_HOST' => 'blog.example.com']);

        self::assertFileDoesNotExist($this->work.'/http.d/site.conf');
        self::assertFileDoesNotExist($this->work.'/snippets/app-docs.conf');
        self::assertFileExists($this->work.'/snippets/app-blog.conf');
    }

    public function test_the_docs_location_needs_stamped_docs(): void
    {
        $this->generate(['SITE_HOST' => 'loupe.ac', 'DEFAULT_URI' => 'https://app.loupe.ac', 'BLOG_PROXY_HOST' => '']);

        self::assertFileExists($this->work.'/http.d/site.conf');
        self::assertFileDoesNotExist($this->work.'/snippets/site-docs.conf');
        self::assertFileDoesNotExist($this->work.'/snippets/site-blog.conf');
    }

    public function test_a_later_run_drops_what_an_earlier_one_wrote(): void
    {
        $this->stampedDocs();
        $this->generate(['SITE_HOST' => 'loupe.ac', 'DEFAULT_URI' => 'https://app.loupe.ac', 'BLOG_PROXY_HOST' => 'blog.example.com']);

        $this->generate(['SITE_HOST' => '', 'DEFAULT_URI' => 'https://app.loupe.ac', 'BLOG_PROXY_HOST' => '']);

        self::assertSame([], glob($this->work.'/snippets/*') ?: []);
        self::assertFileDoesNotExist($this->work.'/http.d/site.conf');
    }

    public function test_it_refuses_a_site_host_that_is_not_a_bare_host_name(): void
    {
        $process = $this->process(['SITE_HOST' => 'loupe.ac; include /etc/passwd', 'DEFAULT_URI' => 'https://app.loupe.ac']);
        $process->run();

        self::assertNotSame(0, $process->getExitCode());
        self::assertFileDoesNotExist($this->work.'/http.d/site.conf');
    }

    private function stampedDocs(): void
    {
        file_put_contents($this->work.'/docs/index.html', '<html></html>');
    }

    /** @param array<string, string> $env */
    private function generate(array $env): void
    {
        $process = $this->process($env);
        $process->mustRun();
    }

    /** @param array<string, string> $env */
    private function process(array $env): Process
    {
        return new Process(['bash', \dirname(__DIR__, 2).'/docker/prod/nginx-config.sh'], null, [
            'NGINX_SNIPPET_DIR' => $this->work.'/snippets',
            'NGINX_HTTP_DIR' => $this->work.'/http.d',
            'DOCS_ROOT' => $this->work.'/docs',
            'RESOLV_CONF' => $this->work.'/resolv.conf',
            'BLOG_PROXY_HOST' => '',
            ...$env,
        ]);
    }

    private function read(string $path): string
    {
        self::assertFileExists($this->work.'/'.$path);

        return (string) file_get_contents($this->work.'/'.$path);
    }
}
