<?php

namespace App\Support;

final class BranchStatuses
{
    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const INACTIVE = 'inactive';

    public const SUSPENDED = 'suspended';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::DRAFT,
            self::ACTIVE,
            self::PAUSED,
            self::INACTIVE,
            self::SUSPENDED,
        ];
    }

    /** @return list<string> */
    public static function ownerAssignable(): array
    {
        return [
            self::DRAFT,
            self::ACTIVE,
            self::PAUSED,
            self::INACTIVE,
        ];
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::DRAFT => 'Draft',
            self::ACTIVE => 'Active',
            self::PAUSED => 'Paused',
            self::INACTIVE => 'Inactive',
            self::SUSPENDED => 'Suspended',
            default => ucfirst($status),
        };
    }

    public static function isOperational(string $status): bool
    {
        return $status === self::ACTIVE;
    }

    public static function allowsConfiguration(string $status): bool
    {
        return in_array($status, [self::DRAFT, self::ACTIVE, self::PAUSED, self::INACTIVE], true);
    }
}
