<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\WorkerRunListQuery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** The filters of the worker run list, read from the query string and checked. */
final readonly class WorkerRunListFilters
{
    private const string TIME = '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/D';

    /**
     * @param array<string, scalar|null> $cursorFilters what a cursor must agree with
     */
    private function __construct(
        public WorkerRunListQuery $query,
        public array $cursorFilters,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $errors = [];
        $states = [];
        foreach (self::repeated($request, 'state') as $value) {
            $state = WorkerRunState::tryFrom($value);
            if (null === $state) {
                $errors[] = ['field' => 'state', 'code' => 'invalid_value', 'message' => \sprintf('Unknown state "%s". Use one of: %s.', $value, implode(', ', array_map(static fn (WorkerRunState $case): string => $case->value, WorkerRunState::cases())))];
                continue;
            }
            $states[$state->value] = $state;
        }
        ksort($states);

        $cardNumber = null;
        $card = self::text($request, 'card');
        if (null !== $card) {
            if (1 !== preg_match('/^[1-9][0-9]{0,8}$/D', $card)) {
                $errors[] = ['field' => 'card', 'code' => 'invalid_value', 'message' => 'card must be a card number, such as 12.'];
            } else {
                $cardNumber = (int) $card;
            }
        }

        $bridgeId = null;
        $bridge = self::text($request, 'bridge');
        if (null !== $bridge) {
            if (!Uuid::isValid($bridge)) {
                $errors[] = ['field' => 'bridge', 'code' => 'invalid_value', 'message' => 'bridge must be the id of a bridge.'];
            } else {
                $bridgeId = Uuid::fromString($bridge);
            }
        }

        $endedAfter = self::time($request, 'endedAfter', $errors);
        $endedBefore = self::time($request, 'endedBefore', $errors);

        if ([] !== $errors) {
            throw ApiProblemException::validationFailed($errors);
        }

        $search = self::text($request, 'q');
        $workKind = self::text($request, 'workKind');
        $harness = self::text($request, 'harness');
        $account = self::text($request, 'account');
        $model = self::text($request, 'model');

        return new self(
            new WorkerRunListQuery(
                search: $search,
                bridgeId: $bridgeId,
                harness: $harness,
                account: $account,
                model: $model,
                states: array_values($states),
                cardNumber: $cardNumber,
                workKind: $workKind,
                endedAfter: $endedAfter,
                endedBefore: $endedBefore,
            ),
            [
                'state' => [] === $states ? null : implode(',', array_keys($states)),
                'card' => $cardNumber,
                'workKind' => $workKind,
                'bridge' => $bridge,
                'harness' => $harness,
                'account' => $account,
                'model' => $model,
                'endedAfter' => $endedAfter?->format(\DATE_ATOM),
                'endedBefore' => $endedBefore?->format(\DATE_ATOM),
                'q' => $search,
            ],
        );
    }

    private static function text(Request $request, string $name): ?string
    {
        $value = $request->query->all()[$name] ?? null;
        $text = \is_string($value) ? trim($value) : '';

        return '' === $text ? null : $text;
    }

    /**
     * Every value of a repeated parameter, written `state=a&state=b` or `state[]=a&state[]=b`. PHP keeps the last value of the first form, so the raw query string is read.
     *
     * @return list<string>
     */
    private static function repeated(Request $request, string $name): array
    {
        $values = [];
        foreach (explode('&', (string) $request->server->get('QUERY_STRING', '')) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            if ($key === $name || $key === $name.'[]') {
                foreach (explode(',', urldecode($value)) as $part) {
                    if ('' !== trim($part)) {
                        $values[] = trim($part);
                    }
                }
            }
        }

        return $values;
    }

    /** @param list<array{field: string, code: string, message: string}> $errors */
    private static function time(Request $request, string $name, array &$errors): ?\DateTimeImmutable
    {
        $value = self::text($request, $name);
        if (null === $value) {
            return null;
        }

        $refusal = ['field' => $name, 'code' => 'invalid_value', 'message' => \sprintf('%s must be an ISO 8601 date, such as 2026-09-30 or 2026-09-30T14:00:00Z.', $name)];
        if (1 !== preg_match(self::TIME, $value)) {
            $errors[] = $refusal;

            return null;
        }

        try {
            $time = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            $errors[] = $refusal;

            return null;
        }

        // PHP rolls an impossible date over, so 2026-02-31 reads as 3 March unless it round-trips.
        $written = substr($value, 0, 10).(\strlen($value) > 10 ? 'T'.substr($value, 11, 5) : '');
        if ($time->format(\strlen($value) > 10 ? 'Y-m-d\TH:i' : 'Y-m-d') !== $written) {
            $errors[] = $refusal;

            return null;
        }

        return $time->setTimezone(new \DateTimeZone('UTC'));
    }
}
