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

    /**
     * A built answer may add a property to its contract schema, as the heartbeat does. It
     * may not drop one, or fall back to an open object, because a client reads its fields
     * from the spec.
     */
    #[DataProvider('areas')]
    public function test_every_built_success_response_carries_its_contract_schema(string $area): void
    {
        $builtSpec = json_decode($this->dump($area), true, flags: \JSON_THROW_ON_ERROR);
        $contractSpec = Yaml::parseFile(self::file('contract/'.$area.'.yaml'));
        $built = $this->operations($builtSpec);
        $contract = $this->operations($contractSpec);
        $compared = 0;

        foreach ($built as $key => $operation) {
            foreach ($contract[$key]['responses'] as $status => $response) {
                $reference = $response['content']['application/json']['schema']['$ref'] ?? null;
                if (!str_starts_with((string) $status, '2') || null === $reference) {
                    continue;
                }

                self::assertSame($reference, $operation['responses'][$status]['content']['application/json']['schema']['$ref'] ?? null, sprintf('%s answers %s with another schema than the contract.', $key, $status));
                $this->assertHoldsContractSchema($reference, $builtSpec, $contractSpec);
                ++$compared;
            }
        }

        self::assertGreaterThan(0, $compared, 'No response was compared, so this test would prove nothing.');
    }

    public function test_a_request_schema_requires_no_field_from_a_helper_method(): void
    {
        $schemas = json_decode($this->dump('private'), true, flags: \JSON_THROW_ON_ERROR)['components']['schemas'];

        self::assertSame(['state'], $schemas['AcknowledgeBridgeCommandRequest']['required']);
        self::assertArrayNotHasKey('textReason', $schemas['AcknowledgeBridgeCommandRequest']['properties']);
        self::assertSame(['claimToken', 'state'], $schemas['SettleWorkRequestRequest']['required']);
        self::assertArrayNotHasKey('validReason', $schemas['SettleWorkRequestRequest']['properties']);
        self::assertArrayNotHasKey('validCause', $schemas['SettleWorkRequestRequest']['properties']);
    }

    /**
     * @param array<string, mixed> $builtSpec
     * @param array<string, mixed> $contractSpec
     */
    private function assertHoldsContractSchema(string $reference, array $builtSpec, array $contractSpec): void
    {
        $name = substr($reference, \strlen('#/components/schemas/'));
        $expected = $contractSpec['components']['schemas'][$name];
        self::assertArrayHasKey($name, $builtSpec['components']['schemas'], sprintf('The built spec has no schema %s.', $name));
        $actual = $builtSpec['components']['schemas'][$name];

        self::assertSame([], array_values(array_diff($expected['required'] ?? [], $actual['required'] ?? [])), sprintf('The built schema %s requires less than the contract.', $name));
        foreach ($expected['properties'] ?? [] as $property => $schema) {
            self::assertEquals(self::withoutEmptyProperties($schema), $actual['properties'][$property] ?? null, sprintf('The built schema %s differs from the contract at %s.', $name, $property));
        }

        array_walk_recursive($expected, function (mixed $value, string|int $key) use ($builtSpec, $contractSpec): void {
            if ('$ref' === $key && \is_string($value)) {
                $this->assertHoldsContractSchema($value, $builtSpec, $contractSpec);
            }
        });
    }

    /**
     * The generator leaves out an empty `properties` key.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function withoutEmptyProperties(array $schema): array
    {
        if ([] === ($schema['properties'] ?? null)) {
            unset($schema['properties']);
        }

        return array_map(static fn (mixed $value): mixed => \is_array($value) ? self::withoutEmptyProperties($value) : $value, $schema);
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
