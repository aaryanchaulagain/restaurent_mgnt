<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class OrderErrorResponse
{
    public static function messageForCode(string $code): string
    {
        return match ($code) {
            'ORDER_ALREADY_CREATED' => 'This order has already been created.',
            'IDEMPOTENCY_KEY_REUSED' => 'This order request key has already been used with different checkout details.',
            'CHECKOUT_QUOTE_EXPIRED' => 'Your checkout quote has expired. Please refresh checkout.',
            'CHECKOUT_QUOTE_CONVERTED' => 'This checkout quote has already been used for an order.',
            'CART_CHANGED' => 'Your cart changed since checkout was prepared.',
            'PRICE_CHANGED' => 'An item price changed. Please review checkout again.',
            'INSUFFICIENT_STOCK' => 'Not enough stock available for one or more items.',
            'ITEM_UNAVAILABLE' => 'An item in your cart is no longer available.',
            'VARIANT_UNAVAILABLE' => 'A selected variant is no longer available.',
            'MODIFIER_UNAVAILABLE' => 'A selected modifier is no longer available.',
            'RESTAURANT_CLOSED' => 'This restaurant is not accepting orders right now.',
            'RESTAURANT_UNAVAILABLE' => 'This restaurant is not available for orders.',
            'ORDER_BRANCH_UNAVAILABLE' => 'This location is not available for orders.',
            'ORDER_BRANCH_CONTEXT_INVALID' => 'The order location context is invalid.',
            'ORDER_TENANT_CONTEXT_INVALID' => 'This location cannot accept orders because its business/branch context is invalid.',
            'ORDER_RESTAURANT_MISSING' => 'The restaurant for this order is missing.',
            'ORDER_BRANCH_MISSING' => 'The branch for this order is missing.',
            'ORDER_BUSINESS_MISSING' => 'The business for this order is missing.',
            'ORDER_BRANCH_RESTAURANT_MISMATCH' => 'Branch and restaurant links do not match.',
            'ORDER_HISTORICAL_RELATION_UNRESOLVED' => 'Historical order relationship could not be resolved.',
            'ORDER_INTEGRITY_REPAIR_NOT_SAFE' => 'Automatic repair is not safe for this order.',
            'ORDER_INTEGRITY_REPAIR_CONFLICT' => 'Integrity repair conflicted with current data.',
            'PAYMENT_ORDER_RELATION_INVALID' => 'Payment does not relate to a valid order.',
            'RESERVATION_ORDER_RELATION_INVALID' => 'Reservation does not relate to a valid order.',
            'CART_ITEM_BRANCH_MISMATCH' => 'A cart item does not belong to the selected location.',
            'CHECKOUT_BRANCH_UNAVAILABLE' => 'This location is unavailable for checkout.',
            'CHECKOUT_BRANCH_NOT_ACCEPTING_ORDERS' => 'This location is not accepting orders right now.',
            'CHECKOUT_CART_BRANCH_CHANGED' => 'Checkout must use the branch locked to your cart.',
            'CHECKOUT_FULFILMENT_UNAVAILABLE' => 'The selected fulfilment method is unavailable.',
            'CHECKOUT_ADDRESS_OUTSIDE_SERVICE_AREA' => 'Delivery is not available for this address.',
            'CHECKOUT_PAYMENT_METHOD_UNAVAILABLE' => 'The selected payment method is unavailable.',
            'MINIMUM_ORDER_NOT_MET' => 'The minimum order amount is not met.',
            'SERVICE_AREA_UNSUPPORTED' => 'Delivery is not available for this address.',
            'INVALID_ORDER_TRANSITION' => 'This order status change is not allowed.',
            'ORDER_ALREADY_ACCEPTED' => 'This order has already been accepted.',
            'ORDER_ALREADY_REJECTED' => 'This order has already been rejected.',
            'ORDER_CANCELLATION_NOT_ALLOWED' => 'This order cannot be cancelled at its current stage.',
            'ORDER_ACCESS_DENIED' => 'You do not have access to this order.',
            default => 'Request could not be completed.',
        };
    }

    public static function extractCodeFromValidation(ValidationException $e): ?string
    {
        foreach ($e->errors() as $messages) {
            foreach ($messages as $message) {
                if (preg_match('/^([A-Z0-9_]+)$/', $message, $m)) {
                    return $m[1];
                }
                if (preg_match('/^([A-Z0-9_]+):/', $message, $m)) {
                    return $m[1];
                }
            }
        }

        return null;
    }
}
