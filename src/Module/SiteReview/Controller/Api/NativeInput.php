<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** What a native note says about the app, the device, the screen and the element. */
final class NativeInput
{
    public function __construct(
        #[Assert\Length(max: 255)]
        public ?string $appName = null,

        #[Assert\Length(max: 255)]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $bundleId = null,

        #[Assert\Length(max: 255)]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $deviceName = null,

        #[Assert\Length(max: 100)]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $udid = null,

        #[Assert\Length(max: 100)]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $osVersion = null,

        #[Assert\Length(max: 2000)]
        public ?string $screenTitle = null,

        #[Assert\Valid]
        public ?NativeElementInput $element = null,
    ) {
    }

    /**
     * The facts as the comment stores them, in one fixed key order. The delivery
     * hash covers this array, so a retry of the same facts must produce the same
     * bytes.
     *
     * @return array<string, mixed>
     */
    public function facts(): array
    {
        return [
            'app' => ['name' => $this->appName, 'bundleId' => $this->bundleId],
            'device' => ['name' => $this->deviceName, 'udid' => $this->udid, 'osVersion' => $this->osVersion],
            'screen' => ['title' => $this->screenTitle],
            'element' => null === $this->element ? null : [
                'role' => $this->element->role,
                'label' => $this->element->label,
                'identifier' => $this->element->identifier,
                'frame' => null === $this->element->frame ? null : [
                    'x' => round($this->element->frame->x ?? 0.0, 2),
                    'y' => round($this->element->frame->y ?? 0.0, 2),
                    'width' => round($this->element->frame->width ?? 0.0, 2),
                    'height' => round($this->element->frame->height ?? 0.0, 2),
                ],
            ],
        ];
    }
}
