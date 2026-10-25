<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\VAPID;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * The one answer to "can this instance send a push". A page, a route, a job
 * and the diagnostic all ask it, so none of them can disagree.
 */
final class PushAvailability
{
    public const string FLAG = 'notifications.web_push.enabled';

    private bool $checked = false;

    private ?PushUnavailableReason $keyProblem = null;

    public function __construct(
        private readonly FeatureFlagService $featureFlags,

        #[Autowire(env: 'default::VAPID_PUBLIC_KEY')]
        private readonly ?string $publicKey,

        #[Autowire(env: 'default::VAPID_PRIVATE_KEY')]
        #[\SensitiveParameter]
        private readonly ?string $privateKey,
    ) {
    }

    public function isAvailable(): bool
    {
        return null === $this->reason();
    }

    public function reason(): ?PushUnavailableReason
    {
        if (!$this->featureFlags->isEnabled(self::FLAG)) {
            return PushUnavailableReason::FlagOff;
        }

        return $this->keyProblem();
    }

    /** The key pair, or null when it is missing, malformed or mismatched. It ignores the flag. */
    public function keys(): ?VapidKeys
    {
        if (null !== $this->keyProblem()) {
            return null;
        }

        return new VapidKeys((string) $this->publicKey, (string) $this->privateKey);
    }

    /** The environment does not change while the process runs, so the probe runs once. */
    private function keyProblem(): ?PushUnavailableReason
    {
        if (!$this->checked) {
            $this->keyProblem = $this->inspect();
            $this->checked = true;
        }

        return $this->keyProblem;
    }

    private function inspect(): ?PushUnavailableReason
    {
        if (null === $this->publicKey || '' === $this->publicKey) {
            return PushUnavailableReason::PublicKeyMissing;
        }

        if (null === $this->privateKey || '' === $this->privateKey) {
            return PushUnavailableReason::PrivateKeyMissing;
        }

        if (!$this->libraryAccepts($this->publicKey, self::encode(str_repeat("\0", 32)))) {
            return PushUnavailableReason::PublicKeyMalformed;
        }

        if (!$this->libraryAccepts(self::encode("\x04".str_repeat("\0", 64)), $this->privateKey)) {
            return PushUnavailableReason::PrivateKeyMalformed;
        }

        return $this->pairMatches($this->publicKey, $this->privateKey) ? null : PushUnavailableReason::PairMismatched;
    }

    private function libraryAccepts(string $publicKey, #[\SensitiveParameter] string $privateKey): bool
    {
        try {
            VAPID::validate(['subject' => 'https://probe.invalid', 'publicKey' => $publicKey, 'privateKey' => $privateKey]);
        } catch (\Exception) {
            return false;
        }

        return true;
    }

    /** Signs a probe token with the private key, then checks the signature against the public key. */
    private function pairMatches(string $publicKey, #[\SensitiveParameter] string $privateKey): bool
    {
        try {
            $accepted = VAPID::validate(['subject' => 'https://probe.invalid', 'publicKey' => $publicKey, 'privateKey' => $privateKey]);
            $headers = VAPID::getVapidHeaders('https://probe.invalid', 'https://probe.invalid', $accepted['publicKey'], $accepted['privateKey'], ContentEncoding::aes128gcm);
            if (1 !== preg_match('/^vapid t=([^,]+),/', $headers['Authorization'], $matches)) {
                return false;
            }

            $jws = new CompactSerializer()->unserialize($matches[1]);
            $publicJwk = new JWK([
                'kty' => 'EC',
                'crv' => 'P-256',
                'x' => self::encode(substr($accepted['publicKey'], 1, 32)),
                'y' => self::encode(substr($accepted['publicKey'], 33, 32)),
            ]);

            return new JWSVerifier(new AlgorithmManager([new ES256()]))->verifyWithKey($jws, $publicJwk, 0);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
