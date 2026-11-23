<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Module\Bridge\Command\OpenWorkerWaitCommand;
use App\Module\Bridge\Command\OpenWorkerWaitHandler;
use App\Module\Bridge\Command\WaitConditionRequest;
use App\Module\Bridge\Command\WorkerWaitRefused;
use App\Module\Bridge\Service\CallerRun;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Uid\Uuid;

/**
 * Opens a wait: the worker parks its session until a condition of its card turns true, or the limit passes.
 */
#[McpTool(name: self::NAME, description: 'Wait for one or more conditions of your card, with a time limit, and then end your turn. Call it when a slow result is due, such as the checks of a pull request you just pushed. Pass your sessionId, conditions and limitMinutes. Each condition is an object with a key, which is a condition of the workflow or a marker key of its template, and optional params. A condition that reads a pull request takes pullRequestUrl, the URL of a pull request the card links. Leave pullRequestUrl out when the card links exactly one open pull request. Pass 1 to 10 conditions. limitMinutes counts from 1, and the workflow sets the longest wait. When the tool accepts the wait, it answers waiting true with the waitId, limitAt and the conditions, and then you end your turn at once and do no more work. Loupe resumes this same session, with the result in the prompt, when one condition turns true or when the limit passes. The tool refuses when: the session has no open bridge worker run, such as an interactive session; the run has no card; limitMinutes is over the longest wait; the chain of resumed runs reached its cap of waits; the card already has an open wait; a condition is unknown, has invalid params, or needs a pull request that you did not name, that the card does not link or that is not open; a condition is named twice; or a condition is true already. The last refusal lists the true conditions, so you read their result and do not wait for it. A refusal opens no wait.')]
final readonly class RunWaitTool
{
    public const string NAME = 'run_wait';

    public const int MAX_CONDITIONS = 10;

    public const array CONDITION_ITEM = [
        'type' => 'object',
        'properties' => [
            'key' => ['type' => 'string', 'description' => 'a condition of the workflow, or a marker key of its template'],
            'params' => ['type' => 'object', 'description' => 'the parameters of the condition, when it takes any'],
            'pullRequestUrl' => ['type' => 'string', 'description' => 'the URL of the pull request the condition reads. Leave it out when the card links exactly one open pull request'],
        ],
        'required' => ['key'],
    ];

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private OpenWorkerWaitHandler $openWait,
        private CallerRun $callerRun,
    ) {
    }

    /**
     * @param string         $sessionId    your own session id
     * @param array<mixed>[] $conditions   the conditions to wait for, 1 to 10 of them
     * @param int            $limitMinutes how long to wait at most, in minutes
     *
     * @return array{waiting: true, waitId: string, limitAt: string, conditions: list<array{key: string, pullRequest: ?string}>, message: string}
     */
    public function __invoke(
        string $sessionId,
        #[Schema(items: self::CONDITION_ITEM, minItems: 1, maxItems: self::MAX_CONDITIONS)] array $conditions,
        #[Schema(minimum: 1)] int $limitMinutes,
    ): array {
        try {
            $project = $this->subjects->requireWritableProject();
            $session = $this->parseSession($sessionId);
            $held = $this->callerRun->workerSession();
            if (null !== $held && !$held->equals($session)) {
                throw new ToolCallException('sessionId must be the session of your own run.');
            }
            $requests = $this->requestsOf($conditions);

            $wait = ($this->openWait)(new OpenWorkerWaitCommand($project, $session, $requests, $limitMinutes));

            return [
                'waiting' => true,
                'waitId' => (string) $wait->id,
                'limitAt' => $wait->limitAt->format(\DATE_ATOM),
                'conditions' => array_map(static fn (array $entry): array => ['key' => $entry['key'], 'pullRequest' => $entry['token']], $wait->conditions),
                'message' => \sprintf('Wait accepted. End your turn now. Loupe resumes this session when a condition turns true, or when the limit passes at %s.', $wait->limitAt->format(\DATE_ATOM)),
            ];
        } catch (WorkerWaitRefused $e) {
            throw new ToolCallException($e->getMessage(), previous: $e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The wait could not be opened. The error has been logged.', previous: $e);
        }
    }

    private function parseSession(string $sessionId): Uuid
    {
        try {
            return Uuid::fromString($sessionId);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException(\sprintf('"%s" is not a valid session ID. Pass your own session id.', $sessionId), previous: $e);
        }
    }

    /**
     * @param array<mixed> $conditions
     *
     * @return non-empty-list<WaitConditionRequest>
     */
    private function requestsOf(array $conditions): array
    {
        if ([] === $conditions || \count($conditions) > self::MAX_CONDITIONS) {
            throw new ToolCallException(\sprintf('Pass from 1 to %d conditions.', self::MAX_CONDITIONS));
        }

        $requests = [];
        foreach (array_values($conditions) as $index => $condition) {
            if (!\is_array($condition) || !\is_string($condition['key'] ?? null) || '' === $condition['key']) {
                throw new ToolCallException(\sprintf('conditions[%d] must be an object with a key.', $index));
            }
            $params = $condition['params'] ?? [];
            if (!\is_array($params) || array_is_list($params) && [] !== $params) {
                throw new ToolCallException(\sprintf('conditions[%d].params must be an object.', $index));
            }
            $url = $condition['pullRequestUrl'] ?? null;
            if (null !== $url && (!\is_string($url) || '' === $url)) {
                throw new ToolCallException(\sprintf('conditions[%d].pullRequestUrl must be a URL.', $index));
            }
            $requests[] = new WaitConditionRequest($condition['key'], $params, $url);
        }

        return $requests;
    }
}
