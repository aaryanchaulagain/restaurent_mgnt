<?php

namespace App\Support;

final class BranchInvitationStatuses
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PENDING, self::ACCEPTED, self::EXPIRED, self::REVOKED];
    }
}
