<?php

declare(strict_types=1);

namespace App\Module\Notification\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Notification\Command\RegisterPushDeviceCommand;
use App\Module\Notification\Command\RegisterPushDeviceHandler;
use App\Module\Notification\Form\RegisterPushDeviceFormType;
use App\Module\Notification\Form\RegisterPushDeviceRequest;
use App\Module\Notification\Service\DeviceCookie;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Called by a fetch() from the settings page. It binds the signed-in user's own
 * subscription, so no voter is needed. A success is 201 with the device and sets
 * the device cookie. A failure is 422 with `message` and `errors` by field, or
 * 409 when another account holds the endpoint, so the browser can subscribe again.
 */
#[Route(
    '/account/notifications/devices',
    name: 'app_account_notifications_device_create',
    methods: ['POST'],
)]
final class RegisterPushDeviceController extends AppController
{
    public function __construct(
        private readonly RegisterPushDeviceHandler $registerDevice,
        private readonly DeviceCookie $deviceCookie,

        #[Autowire(service: 'limiter.notification_device_create')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        if (!$this->limiter->create('user:'.$user->getUserIdentifier())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many device registrations.');
        }

        $data = new RegisterPushDeviceRequest();
        $form = $this->createForm(RegisterPushDeviceFormType::class, $data);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->failure($form);
        }

        $token = $this->deviceCookie->tokenFrom($request) ?? $this->deviceCookie->generate();

        try {
            $device = ($this->registerDevice)(new RegisterPushDeviceCommand(
                user: $user,
                endpoint: $data->endpoint ?? '',
                publicKey: $data->publicKey ?? '',
                authToken: $data->authToken ?? '',
                contentEncoding: $data->contentEncoding ?? 'aes128gcm',
                label: $data->label,
                userAgent: $request->headers->get('User-Agent'),
                deviceTokenHash: DeviceCookie::hash($token),
            ));
        } catch (DomainErrors $e) {
            $this->applyDomainErrors($form, $e);
            $taken = 'notification.device.error.endpoint_taken' === ($e->errors['endpoint'] ?? null);

            return $this->failure($form, $taken ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $response = new JsonResponse(['id' => (string) $device->id, 'label' => $device->label], Response::HTTP_CREATED);
        $response->headers->setCookie($this->deviceCookie->cookie($token, $request->isSecure()));

        return $response;
    }

    /** @param FormInterface<mixed> $form */
    private function failure(FormInterface $form, int $status = Response::HTTP_UNPROCESSABLE_ENTITY): JsonResponse
    {
        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $origin = $error->getOrigin();
            $field = null === $origin || $origin->isRoot() ? 'form' : $origin->getName();
            $errors[$field][] = $error->getMessage();
        }

        $first = array_first($errors)[0] ?? '';

        return new JsonResponse(['message' => $first, 'errors' => $errors], $status);
    }
}
