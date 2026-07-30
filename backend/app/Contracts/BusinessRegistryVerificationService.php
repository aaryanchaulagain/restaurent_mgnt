<?php

namespace App\Contracts;

interface BusinessRegistryVerificationService
{
    /**
     * Local format/checksum validation only unless an external provider is wired.
     *
     * @return array{valid: bool, verified_externally: bool, message: string}
     */
    public function verifyAbn(string $abn): array;
}
