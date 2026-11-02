<?php

declare(strict_types=1);

namespace App\Api\EventListener;

use App\Api\Problem\ApiPaths;
use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Renders the exceptions of an API path as JSON, whatever the Accept header says.
 *
 * A /api/v1 path and the versions route answer Problem JSON. Any other /api path
 * keeps the old flat `{error, ...extras}` body for a thrown ApiProblemException,
 * and every other exception on it is left alone, so an old client sees what it saw.
 *
 * The priority sits below the security exception listener (1), which owns a refused
 * or missing credential, and above the framework error listener (-128).
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
final readonly class RenderApiProblemOnException
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!$event->isMainRequest() || !ApiPaths::isApi($path)) {
            return;
        }

        $problemJson = ApiPaths::usesProblemJson($path);
        $problem = $this->problemFor($event->getThrowable(), $request, $problemJson);
        if (null === $problem) {
            return;
        }

        $response = new JsonResponse($problemJson ? $problem->toProblemBody() : $problem->toLegacyBody(), $problem->status);
        if ($problemJson) {
            $response->headers->set('Content-Type', 'application/problem+json');
        }
        foreach ($problem->headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        $event->setResponse($response);
    }

    private function problemFor(\Throwable $exception, Request $request, bool $problemJson): ?ApiProblem
    {
        if ($exception instanceof ApiProblemException) {
            return $exception->problem;
        }
        if (!$problemJson) {
            return null;
        }

        $routeMatched = $request->attributes->has('_route');

        if (!$routeMatched && ($exception instanceof NotFoundHttpException || $exception instanceof MethodNotAllowedHttpException)) {
            return $this->unmatchedRoute($exception, $request);
        }
        if ($exception instanceof TooManyRequestsHttpException) {
            return ApiProblemException::rateLimited((int) ($exception->getHeaders()['Retry-After'] ?? 60))->problem;
        }
        if ($exception instanceof UnsupportedMediaTypeHttpException) {
            return new ApiProblem(415, 'unsupported_media_type', 'Unsupported media type', 'The body must be application/json.');
        }
        if ($exception instanceof HttpExceptionInterface) {
            return $this->fromHttpException($exception);
        }
        if (!$this->isHandledElsewhere($exception)) {
            $this->logger->critical('api.unhandled_exception', ['exception' => $exception, 'path' => $request->getPathInfo()]);

            return new ApiProblem(500, 'internal_error', 'Internal error', 'The server failed to answer this request.');
        }

        return null;
    }

    private function unmatchedRoute(NotFoundHttpException|MethodNotAllowedHttpException $exception, Request $request): ApiProblem
    {
        $version = ApiPaths::requestedVersion($request->getPathInfo());
        if (null !== $version && !\in_array($version, ApiPaths::SERVED_VERSIONS, true)) {
            return new ApiProblem(
                404,
                'api_version_unsupported',
                'API version not supported',
                sprintf('This server does not serve API %s.', $version),
                extras: ['versions' => ApiPaths::SERVED_VERSIONS],
            );
        }

        if ($exception instanceof MethodNotAllowedHttpException) {
            $allow = $exception->getHeaders()['Allow'] ?? '';

            return new ApiProblem(
                405,
                'method_not_allowed',
                'Method not allowed',
                'This route does not answer this method.',
                headers: '' === $allow ? [] : ['Allow' => $allow],
            );
        }

        return new ApiProblem(404, 'route_not_found', 'Route not found', 'No route answers this path.');
    }

    private function fromHttpException(HttpExceptionInterface $exception): ApiProblem
    {
        $previous = $exception->getPrevious();
        if ($previous instanceof ValidationFailedException) {
            return ApiProblemException::validationFailed($this->errorsOf($previous))->problem;
        }
        if (400 === $exception->getStatusCode() && ($previous instanceof SerializerException || $previous instanceof \UnexpectedValueException)) {
            return new ApiProblem(400, 'invalid_json', 'Invalid JSON', 'The body does not parse.');
        }

        $status = $exception->getStatusCode();
        $title = Response::$statusTexts[$status] ?? 'Error';

        // The message of a framework exception can name an internal class, so no answer repeats it.
        return new ApiProblem(
            $status,
            'http_'.$status,
            $title,
            sprintf('The server answered this request with status %d (%s).', $status, $title),
            headers: array_map(strval(...), $exception->getHeaders()),
        );
    }

    /** @return list<array{field: string, code: string, message: string}> */
    private function errorsOf(ValidationFailedException $exception): array
    {
        $errors = [];
        foreach ($exception->getViolations() as $violation) {
            $errors[] = [
                'field' => $violation->getPropertyPath(),
                'code' => $this->codeOf($violation),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return $errors;
    }

    private function codeOf(ConstraintViolationInterface $violation): string
    {
        $constraint = $violation instanceof ConstraintViolation ? $violation->getConstraint() : null;
        $name = null === $constraint ? 'invalid' : strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', new \ReflectionClass($constraint)->getShortName()));
        if ('not_blank' === $name) {
            $name = 'blank';
        }
        $field = (string) preg_replace('/[^a-z0-9]+/', '_', strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $violation->getPropertyPath())));
        $field = trim($field, '_');

        return '' === $field ? $name : $field.'_'.$name;
    }

    /** The security listener renders these, and a framework 404 for a missing entity is not an exception to hide. */
    private function isHandledElsewhere(\Throwable $exception): bool
    {
        return $exception instanceof AccessDeniedException
            || $exception instanceof AuthenticationException;
    }
}
