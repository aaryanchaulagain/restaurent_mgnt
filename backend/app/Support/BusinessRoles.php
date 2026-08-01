<?php

namespace App\Support;

final class BusinessRoles
{
    public const BUSINESS_OWNER = 'business_owner';

    public const BUSINESS_ADMIN = 'business_admin';

    public const ACCOUNTANT = 'accountant';

    public const BRANCH_MANAGER = 'branch_manager';

    public const ORDER_MANAGER = 'order_manager';

    public const KITCHEN_STAFF = 'kitchen_staff';

    public const INVENTORY_MANAGER = 'inventory_manager';

    public const DELIVERY_STAFF = 'delivery_staff';

    /** Roles that may manage business profile, branches, and staff. */
    /** @return list<string> */
    public static function businessManagers(): array
    {
        return [self::BUSINESS_OWNER, self::BUSINESS_ADMIN];
    }

    /** @return list<string> */
    public static function businessLevel(): array
    {
        return self::businessAssignable();
    }

    /** @return list<string> */
    public static function businessAssignable(): array
    {
        return [self::BUSINESS_OWNER, self::BUSINESS_ADMIN, self::ACCOUNTANT];
    }

    /** @return list<string> */
    public static function branchLevel(): array
    {
        return [
            self::BRANCH_MANAGER,
            self::ORDER_MANAGER,
            self::KITCHEN_STAFF,
            self::INVENTORY_MANAGER,
            self::DELIVERY_STAFF,
        ];
    }

    /** Roles a branch manager may assign (operational only). */
    /** @return list<string> */
    public static function branchManagerAssignable(): array
    {
        return [
            self::ORDER_MANAGER,
            self::KITCHEN_STAFF,
            self::INVENTORY_MANAGER,
            self::DELIVERY_STAFF,
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(self::businessAssignable(), self::branchLevel());
    }

    /**
     * Map branch role → legacy restaurant_* role.
     */
    public static function toLegacyRestaurantRole(string $branchOrBusinessRole): ?string
    {
        return match ($branchOrBusinessRole) {
            self::BUSINESS_OWNER => 'restaurant_owner',
            self::BUSINESS_ADMIN, self::BRANCH_MANAGER => 'restaurant_manager',
            self::ACCOUNTANT,
            self::ORDER_MANAGER,
            self::KITCHEN_STAFF,
            self::INVENTORY_MANAGER,
            self::DELIVERY_STAFF => 'restaurant_staff',
            default => null,
        };
    }

    /**
     * Map legacy restaurant_* role slug to business/branch roles.
     *
     * @return array{business?: string, branch?: string}
     */
    public static function fromRestaurantRole(?string $roleSlug): array
    {
        return match ($roleSlug) {
            'restaurant_owner' => [
                'business' => self::BUSINESS_OWNER,
                'branch' => self::BRANCH_MANAGER,
            ],
            'restaurant_manager' => [
                'branch' => self::BRANCH_MANAGER,
            ],
            'restaurant_staff' => [
                'branch' => self::ORDER_MANAGER,
            ],
            default => [],
        };
    }
}
