<?php

namespace App\Events\Branch;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BranchUpdated
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function __construct(
        public readonly Branch $branch,
        public readonly array $oldValues = [],
        public readonly array $newValues = [],
        public readonly ?User $actor = null,
    ) {}
}
