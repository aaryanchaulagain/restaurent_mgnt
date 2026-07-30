<?php

namespace App\Services\Partner;

use App\Contracts\BusinessRegistryVerificationService;
use App\Support\Abn;

class LocalAbnVerificationService implements BusinessRegistryVerificationService
{
    public function verifyAbn(string $abn): array
    {
        $valid = Abn::isValid($abn);

        return [
            'valid' => $valid,
            'verified_externally' => false,
            'message' => $valid
                ? 'ABN format and checksum are valid. External government verification was not performed.'
                : 'ABN format or checksum is invalid.',
        ];
    }
}
