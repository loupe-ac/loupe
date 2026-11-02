<?php

declare(strict_types=1);

namespace App\Tests\Api\OpenApi;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps the committed specs under openapi/ equal to what the generator builds from
 * the controllers, and keeps every built operation inside the reviewed contract.
 *
 * The contract files under openapi/contract/ hold the whole reviewed API. Until the
 * docs card makes the comparison demand the whole spec, they cover the routes that
 * exist and no more: an operation that is built must be in the contract, and a
 * contract operation that is not built yet is no failure.
 *
 * Rebuild a committed file with `just openapi-dump`.
 */
final class GeneratedSpecTest extends KernelTestCase
{
    /** @return iterable<string, array{string}> */
    public static function areas(): iterable
    {
        yield 'public' => ['public'];
        yield 'private' => ['private'];
    }

    #[DataProvider('areas')]
    public function test_the_committed_spec_equals_a_fresh_build(string $area): void
    {
        self::assertSame(
            (string) file_get_contents(self::file($area.'.json')),
            $this->dump($area),
            sprintf('openapi/%s.json is stale. Run `just openapi-dump` and commit the result.', $area),
        );
    }

    #[DataProvider('areas')]
    public function test_every_built_operation_is_in_the_reviewed_contract(string $area): void
    {
        $built = $this->operations(json_decode($this->dump($area), true, flags: \JSON_THROW_ON_ERROR));
        $contract = $this->operations(Yaml::parseFile(self::file('contract/'.$area.'.yaml')));
        if ('public' === $area) {
            self::assertNotEmpty($built, 'The public spec holds no operation, so this test would prove nothing.');
        }

        foreach ($built as $key => $operation) {
            self::assertArrayHasKey($key, $contract, sprintf('%s is built but the reviewed contract has no such operation.', $key));
            self::assertSame($contract[$key]['operationId'], $operation['operationId'], sprintf('%s has another operationId in the contract.', $key));

            $extra = array_diff(array_keys($operation['responses']), array_keys($contract[$key]['responses']));
            self::assertSame([], array_values($extra), sprintf('%s answers statuses that the contract does not list.', $key));
        }
    }

    #[DataProvider('areas')]
    public function test_every_built_response_declares_the_headers_of_the_contract(string $area): void
    {
        $builtSpec = json_decode($this->dump($area), true, flags: \JSON_THROW_ON_ERROR);
        $contractSpec = Yaml::parseFile(self::file('contract/'.$area.'.yaml'));
        $contract = $this->operations($contractSpec);

        foreach ($this->operations($builtSpec) as $key => $operation) {
            foreach ($operation['responses'] as $status => $response) {
                $expected = self::resolved($contractSpec, $contract[$key]['responses'][$status])['headers'] ?? [];
                $missing = array_diff(array_keys($expected), array_keys(self::resolved($builtSpec, $response)['headers'] ?? []));
                self::assertSame([], array_values($missing), sprintf('%s %s lacks headers of the contract.', $key, $status));
            }
        }
    }

    public function test_a_429_of_a_public_route_declares_retry_after(): void
    {
        $spec = json_decode($this->dump('public'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('Retry-After', $spec['components']['headers']);

        foreach ($this->operations($spec) as $key => $operation) {
            self::assertArrayHasKey('Retry-After', $operation['responses']['429']['headers'] ?? [], $key);
        }
    }

    public function test_every_public_request_schema_has_the_nullable_fields_and_the_required_fields_of_the_contract(): void
    {
        $builtSpec = json_decode($this->dump('public'), true, flags: \JSON_THROW_ON_ERROR);
        $contractSpec = Yaml::parseFile(self::file('contract/public.yaml'));
        $contract = $contractSpec['paths'];
        $checked = 0;

        foreach ($builtSpec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (!isset($operation['requestBody'])) {
                    continue;
                }

                $built = self::resolved($builtSpec, $operation['requestBody']['content']['application/json']['schema']);
                $expected = self::resolved($contractSpec, $contract[$path][$method]['requestBody']['content']['application/json']['schema']);
                $key = strtoupper((string) $method).' '.$path;

                self::assertEqualsCanonicalizing($expected['required'] ?? [], $built['required'] ?? [], $key);
                self::assertEqualsCanonicalizing(array_keys($expected['properties']), array_keys($built['properties']), $key);
                foreach ($built['properties'] as $name => $property) {
                    $label = $key.' '.$name;
                    self::assertSame($expected['properties'][$name]['nullable'] ?? false, $property['nullable'] ?? false, $label);
                    self::assertSame($expected['properties'][$name]['maxLength'] ?? null, $property['maxLength'] ?? null, $label);
                    self::assertSame($expected['properties'][$name]['minLength'] ?? null, $property['minLength'] ?? null, $label);
                    self::assertArrayNotHasKey('oneOf', $property, $label);
                    if (\array_key_exists('default', $property)) {
                        self::assertNotNull($property['default'], $label);
                    }
                }
                ++$checked;
            }
        }

        self::assertGreaterThanOrEqual(5, $checked, 'The public spec holds too few request bodies, so this test would prove little.');
    }

    #[DataProvider('areas')]
    public function test_the_problem_schema_requires_every_standard_member(string $area): void
    {
        $specs = [
            'built' => json_decode($this->dump($area), true, flags: \JSON_THROW_ON_ERROR),
            'contract' => Yaml::parseFile(self::file('contract/'.$area.'.yaml')),
        ];

        foreach ($specs as $name => $spec) {
            self::assertEqualsCanonicalizing(['type', 'title', 'status', 'code', 'detail'], $spec['components']['schemas']['Problem']['required'], $name);
        }
    }

    public function test_the_public_spec_holds_no_bridge_or_widget_path_and_the_private_spec_holds_only_those(): void
    {
        $public = array_keys(json_decode($this->dump('public'), true, flags: \JSON_THROW_ON_ERROR)['paths']);
        $private = array_keys(json_decode($this->dump('private'), true, flags: \JSON_THROW_ON_ERROR)['paths']);

        foreach ($public as $path) {
            self::assertDoesNotMatchRegularExpression('#^/api/v1/(bridge|live-review-widget)(/|$)#', (string) $path);
        }
        foreach ($private as $path) {
            self::assertMatchesRegularExpression('#^/api/v1/(bridge|live-review-widget)(/|$)#', (string) $path);
        }
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return array<string, array{operationId: string, responses: array<string, mixed>}>
     */
    private function operations(array $spec): array
    {
        $operations = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $operations[strtoupper((string) $method).' '.$path] = ['operationId' => $operation['operationId'], 'responses' => $operation['responses']];
            }
        }

        return $operations;
    }

    /**
     * Follows a `$ref` of the same document.
     *
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private static function resolved(array $spec, array $node): array
    {
        if (!isset($node['$ref'])) {
            return $node;
        }

        $found = $spec;
        foreach (\array_slice(explode('/', (string) $node['$ref']), 1) as $segment) {
            $found = $found[$segment];
        }

        return $found;
    }

    private function dump(string $area): string
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'nelmio:apidoc:dump', '--area' => $area, '--format' => 'json']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        return $tester->getDisplay();
    }

    private static function file(string $name): string
    {
        return dirname(__DIR__, 3).'/openapi/'.$name;
    }
}
