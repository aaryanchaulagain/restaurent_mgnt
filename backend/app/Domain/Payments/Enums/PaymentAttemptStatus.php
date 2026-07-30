<?php

namespace App\Domain\Payments\Enums;

enum PaymentAttemptStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case RequiresAction = 'requires_action';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
