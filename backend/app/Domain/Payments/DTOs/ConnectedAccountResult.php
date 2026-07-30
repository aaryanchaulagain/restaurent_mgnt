<?php

namespace App\Domain\Payments\DTOs;

final readonly class ConnectedAccountResult
{
    /**
     * @param  list<string>  $requirementsCurrentlyDue
     * @param  list<string>  $requirementsEventuallyDue
     */
    public function __construct(
        public string $externalAccountId,
        public string $onboardingStatus,
        public bool $chargesEnabled,
        public bool $payoutsEnabled,
        public bool $detailsSubmitted,
        public array $requirementsCurrentlyDue,
        public array $requirementsEventuallyDue,
        public ?string $disabledReason,
    ) {}
}
