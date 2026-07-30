<?php

namespace App\Services\Order;

use App\Models\Cart;
use App\Models\CheckoutQuote;

class OrderIdempotencyHasher
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function hash(CheckoutQuote $quote, Cart $cart, string $scope, array $input): string
    {
        $contact = [
            'name' => $this->norm($input['customer_name'] ?? null),
            'email' => $this->normEmail($input['customer_email'] ?? null),
            'phone' => $this->norm($input['customer_phone'] ?? null),
        ];

        $payload = [
            'address_snapshot' => $this->canonicalValue($quote->address_snapshot),
            'cart_public_id' => $cart->public_id,
            'cart_version' => (int) $cart->version,
            'checkout_quote_public_id' => $quote->public_id,
            'contactless_delivery' => (bool) ($input['contactless_delivery'] ?? false),
            'customer_instructions' => $this->norm($input['customer_notes'] ?? null),
            'customer_scope' => $scope,
            'delivery_instructions' => $this->norm($input['delivery_instructions'] ?? null),
            'fulfilment_type' => $quote->fulfilment_type,
            'payment_method' => $input['payment_method'] ?? 'cash',
            'pickup_instructions' => $this->norm($input['pickup_instructions'] ?? null),
            'contact' => $contact,
        ];

        return hash('sha256', json_encode($this->canonicalValue($payload), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function customerScope(?int $customerId, Cart $cart): string
    {
        if ($customerId) {
            return 'customer:'.$customerId;
        }

        return 'guest_cart:'.$cart->public_id;
    }

    private function norm(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normEmail(?string $value): ?string
    {
        $n = $this->norm($value);

        return $n ? strtolower($n) : null;
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->canonicalValue($v), $value);
        }
        ksort($value);

        return array_map(fn ($v) => $this->canonicalValue($v), $value);
    }
}
