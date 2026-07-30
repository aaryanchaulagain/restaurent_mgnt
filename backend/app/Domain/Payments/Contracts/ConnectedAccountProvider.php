<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\DTOs\ConnectedAccountResult;
use App\Domain\Payments\DTOs\OnboardingLinkResult;
use App\Models\Restaurant;

interface ConnectedAccountProvider
{
    public function createAccount(Restaurant $restaurant): ConnectedAccountResult;

    public function createOnboardingLink(Restaurant $restaurant): OnboardingLinkResult;

    public function refreshAccountStatus(Restaurant $restaurant): ConnectedAccountResult;
}
