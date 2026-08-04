<?php

namespace App\Support;

/**
 * Backend-controlled permission templates for business/branch roles.
 * Uses existing restaurant_* permission slugs so portal routes stay stable.
 */
final class BranchRolePermissionMatrix
{
    /** @return list<string> */
    public static function forRole(?string $role): array
    {
        return match ($role) {
            BusinessRoles::BUSINESS_OWNER => self::businessOwner(),
            BusinessRoles::BUSINESS_ADMIN => self::businessAdmin(),
            BusinessRoles::ACCOUNTANT => self::accountant(),
            BusinessRoles::BRANCH_MANAGER => self::branchManager(),
            BusinessRoles::ORDER_MANAGER => self::orderManager(),
            BusinessRoles::INVENTORY_MANAGER => self::inventoryManager(),
            BusinessRoles::KITCHEN_STAFF => self::kitchenStaff(),
            BusinessRoles::DELIVERY_STAFF => self::deliveryStaff(),
            default => [],
        };
    }

    /** @return list<string> */
    public static function businessOwner(): array
    {
        return array_values(array_unique(array_merge(self::businessAdmin(), [
            'activate_restaurant',
            'request_restaurant_refund',
            'manage_payment_accounts',
            'manage_restaurant_staff',
        ])));
    }

    /** @return list<string> */
    public static function businessAdmin(): array
    {
        return [
            'view_restaurant_dashboard',
            'view_restaurant_profile',
            'manage_restaurant_profile',
            'manage_restaurant_media',
            'manage_restaurant_hours',
            'manage_restaurant_service_areas',
            'temporarily_close_restaurant',
            'manage_restaurant_settings',
            'manage_restaurant_staff',
            'manage_menu',
            'view_menu',
            'manage_menu_categories',
            'manage_menu_items',
            'manage_menu_variants',
            'manage_menu_modifiers',
            'manage_menu_allergens',
            'manage_menu_availability',
            'view_inventory',
            'manage_inventory',
            'manage_offers',
            'manage_restaurant_offers',
            'view_orders',
            'manage_orders',
            'view_restaurant_orders',
            'accept_restaurant_orders',
            'reject_restaurant_orders',
            'prepare_restaurant_orders',
            'complete_restaurant_orders',
            'cancel_restaurant_orders',
            'view_finance',
            'view_settlements',
            'view_restaurant_payment_summaries',
            'view_branch_staff',
            'invite_branch_staff',
            'manage_branch_staff',
            'invite_branch_manager',
            'view_all_business_branches',
            'manage_business_branches',
            'manage_business_staff',
            'manage_business_profile',
        ];
    }

    /** @return list<string> */
    public static function accountant(): array
    {
        return [
            'view_restaurant_dashboard',
            'view_finance',
            'view_settlements',
            'view_restaurant_payment_summaries',
            'view_all_business_branches',
        ];
    }

    /** @return list<string> */
    public static function branchManager(): array
    {
        return [
            'view_restaurant_dashboard',
            'view_restaurant_profile',
            'manage_restaurant_profile',
            'manage_restaurant_media',
            'manage_restaurant_hours',
            'manage_restaurant_service_areas',
            'temporarily_close_restaurant',
            'manage_restaurant_settings',
            'manage_menu',
            'view_menu',
            'manage_menu_categories',
            'manage_menu_items',
            'manage_menu_variants',
            'manage_menu_modifiers',
            'manage_menu_allergens',
            'manage_menu_availability',
            'view_inventory',
            'manage_inventory',
            'manage_offers',
            'manage_restaurant_offers',
            'view_orders',
            'manage_orders',
            'view_restaurant_orders',
            'accept_restaurant_orders',
            'reject_restaurant_orders',
            'prepare_restaurant_orders',
            'complete_restaurant_orders',
            'cancel_restaurant_orders',
            'view_restaurant_payment_summaries',
            'view_branch_staff',
            'invite_branch_staff',
            'manage_branch_staff',
            // Explicitly NO: manage_payment_accounts, request_restaurant_refund,
            // activate_restaurant, manage_business_*, invite_branch_manager by default
        ];
    }

    /** @return list<string> */
    public static function orderManager(): array
    {
        return [
            'view_restaurant_dashboard',
            'view_menu',
            'view_orders',
            'view_restaurant_orders',
            'accept_restaurant_orders',
            'reject_restaurant_orders',
            'prepare_restaurant_orders',
            'complete_restaurant_orders',
        ];
    }

    /** @return list<string> */
    public static function inventoryManager(): array
    {
        return [
            'view_restaurant_dashboard',
            'view_menu',
            'manage_menu_availability',
            'view_inventory',
            'manage_inventory',
        ];
    }

    /** @return list<string> */
    public static function kitchenStaff(): array
    {
        return [
            'view_restaurant_dashboard',
            'view_menu',
            'manage_menu_availability',
            'view_orders',
            'view_restaurant_orders',
            'prepare_restaurant_orders',
        ];
    }

    /** @return list<string> */
    public static function deliveryStaff(): array
    {
        return [
            'view_restaurant_dashboard',
            'view_orders',
            'view_restaurant_orders',
            'complete_restaurant_orders',
        ];
    }
}
