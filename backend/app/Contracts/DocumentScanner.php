<?php

namespace App\Contracts;

use Illuminate\Http\UploadedFile;

interface DocumentScanner
{
    /**
     * Placeholder for future malware scanning integrations.
     *
     * @return array{clean: bool, engine: string, details: ?string}
     */
    public function scan(UploadedFile $file): array;
}
