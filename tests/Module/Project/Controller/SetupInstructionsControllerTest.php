<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class SetupInstructionsControllerTest extends WebTestCase
{
    private const string PROJECT_ID = '0192f4c8-7b1e-7a3d-9c2f-5e6a7b8c9d0e';

    public function test_an_anonymous_agent_reads_markdown_with_the_instance_url(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/setup.md');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/markdown; charset=utf-8');
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('loupe init --url http://localhost --mcp', $body);
        self::assertStringContainsString('curl -fsSL http://localhost/install.sh | LOUPE_INSTALL_NO_TTY=1 sh', $body);
        self::assertStringContainsString('<project id>', $body);
        // Shell redirections in the steps prove the text is not HTML-escaped.
        self::assertStringContainsString('log=$(mktemp "${TMPDIR:-/tmp}/loupe-init.XXXXXX")', $body);
        self::assertStringContainsString('> "$log" 2>&1 < /dev/null &', $body);
        self::assertStringNotContainsString('/tmp/loupe-init.log', $body);
        self::assertStringNotContainsString('&gt;', $body);
        self::assertStringNotContainsString('&amp;', $body);
        self::assertStringNotContainsString('&lt;', $body);
    }

    public function test_the_steps_run_loupe_init_then_check_then_finish(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/setup.md');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString("\n## 3. Run loupe init\n", $body);
        self::assertStringContainsString("\n## 4. Check\n", $body);
        self::assertStringContainsString("\n## 5. Finish\n", $body);
        self::assertLessThan(strpos($body, '## 4. Check'), strpos($body, '## 3. Run loupe init'));
        self::assertLessThan(strpos($body, '## 5. Finish'), strpos($body, '## 4. Check'));
        self::assertStringContainsString('loupe status', $body);
        self::assertStringContainsString('.loupe/lifecycle.md', $body);
    }

    public function test_the_steps_no_longer_hand_write_what_loupe_init_does(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/setup.md?project='.self::PROJECT_ID);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('Worker folders', $body);
        self::assertStringNotContainsString('appPrompts', $body);
        self::assertStringNotContainsString('action: command', $body);
        self::assertStringNotContainsString('loupe login', $body);
        self::assertStringNotContainsString('loupe project link', $body);
        self::assertStringNotContainsString('codex mcp add', $body);
        self::assertStringNotContainsString('Claude Code only', $body);
        self::assertStringNotContainsString('Codex only', $body);
    }

    public function test_the_instance_check_reads_sources_that_name_an_instance(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/setup.md');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Its `Instance:` line names the instance of the CLI login.', $body);
        self::assertStringContainsString('`claude mcp get loupe`', $body);
        self::assertStringContainsString('an instance other than http://localhost', $body);
        self::assertStringNotContainsString('If either names another instance', $body);
    }

    public function test_a_valid_project_id_fills_the_commands(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/setup.md?project='.self::PROJECT_ID);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('loupe init --url http://localhost --project '.self::PROJECT_ID.' --mcp', $body);
        self::assertStringNotContainsString('<project id>', $body);
    }

    public function test_an_invalid_project_value_is_dropped(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/setup.md', ['project' => 'evil"; rm -rf ~']);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('evil', $body);
        self::assertStringNotContainsString('rm -rf', $body);
        self::assertStringContainsString('<project id>', $body);
    }

    public function test_an_array_project_value_is_dropped(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/setup.md?project[]='.self::PROJECT_ID);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::PROJECT_ID, (string) $client->getResponse()->getContent());
    }
}
