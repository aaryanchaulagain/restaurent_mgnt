<?php

namespace App\Services\Partner;

use App\Contracts\DocumentScanner;
use Illuminate\Http\UploadedFile;

class NullDocumentScanner implements DocumentScanner
{
    public function scan(UploadedFile $file): array
    {
        return [
            'clean' => true,
            'engine' => 'null',
            'details' => 'Malware scanning is not enabled in this environment.',
        ];
    }
}
