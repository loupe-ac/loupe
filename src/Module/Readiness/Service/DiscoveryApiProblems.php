<?php

declare(strict_types=1);

namespace App\Module\Readiness\Service;

use App\Api\Problem\ApiProblemException;
use App\Exception\DomainErrors;
use App\Module\Readiness\Command\DiscoveryRunning;
use App\Module\Readiness\Command\StartDiscoveryHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Turns what a discovery start refuses with into the coded problems of the public API. */
final readonly class DiscoveryApiProblems
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /** A refusal that no code covers stays as it is, so it is logged as a failure. */
    public function from(DomainErrors|DiscoveryRunning $refusal): ApiProblemException|DomainErrors
    {
        if ($refusal instanceof DiscoveryRunning) {
            return ApiProblemException::refused(409, 'discovery_running', $this->translator->trans(DiscoveryRunning::MESSAGE, ['%number%' => $refusal->cardNumber]), ['cardNumber' => $refusal->cardNumber]);
        }

        $key = array_first($refusal->errors);
        $code = match ($key) {
            StartDiscoveryHandler::NO_LIVE_BRIDGE => 'no_bridge',
            StartDiscoveryHandler::NO_CAPABLE_BRIDGE, StartDiscoveryHandler::NO_CAPABLE_BRIDGE_APP_PROMPTS => 'no_capable_bridge',
            StartDiscoveryHandler::BRIDGE_PAUSED => 'bridge_paused',
            StartDiscoveryHandler::NO_WORKFLOW => 'no_workflow',
            StartDiscoveryHandler::AUTOMATION_OFF => 'automation_off',
            default => null,
        };

        return null === $code ? $refusal : ApiProblemException::refused(409, $code, $this->translator->trans($key));
    }
}
