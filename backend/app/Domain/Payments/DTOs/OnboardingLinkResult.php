<?php

namespace App\Domain\Payments\DTOs;

use Carbon\CarbonInterface;

final readonly class OnboardingLinkResult
{
    public function __construct(
        public string $url,
        public ?CarbonInterface $expiresAt,
    ) {}
}
