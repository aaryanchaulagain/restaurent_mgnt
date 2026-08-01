<?php

namespace App\Events\Branch;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BranchStaffRemoved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Branch $branch,
        public readonly User $user,
        public readonly ?User $actor = null,
    ) {}
}
