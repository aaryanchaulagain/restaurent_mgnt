"use client";

import Link from "next/link";
import { Button } from "@/components/ui/button";
import { useCart } from "@/features/cart/components/cart-provider";
import { cartBranchLabel, cartLocality } from "@/features/cart/lib/cart-branch-label";
import { formatCents } from "@/lib/utils";

export default function CartPage() {
  const { cart, pricing, isLoading, removeLine, updateQuantity } = useCart();

  if (isLoading) {
    return <main className="mx-auto max-w-3xl px-4 py-12">Loading cart…</main>;
  }

  if (!cart) {
    return (
      <main className="mx-auto max-w-3xl px-4 py-12 text-center">
        <h1 className="text-3xl">Your cart is empty</h1>
        <Link href="/restaurants" className="mt-6 inline-block text-[var(--color-burnt-orange)]">
          Browse restaurants
        </Link>
      </main>
    );
  }

  const locality = cartLocality(cart);

  return (
    <main className="mx-auto max-w-3xl px-4 py-8">
      <h1 className="text-4xl">Cart</h1>
      <p className="mt-2 text-sm text-[var(--text-muted)]">
        Ordering from {cartBranchLabel(cart)}
        {locality ? ` · ${locality}` : ""}
      </p>
      {cart.accepting_orders === false ? (
        <p className="mt-3 text-sm text-amber-800">
          This location is not accepting orders right now. You can keep browsing, but checkout is unavailable.
        </p>
      ) : null}
      <ul className="mt-8 space-y-6">
        {cart.items.map((item) => (
          <li key={item.public_id} className="flex items-center justify-between gap-4 border-b pb-4">
            <div>
              <p className="font-medium">{item.name}</p>
              <div className="mt-2 inline-flex items-center gap-2">
                <button type="button" onClick={() => updateQuantity(item.public_id, Math.max(1, item.quantity - 1))}>
                  −
                </button>
                <span>{item.quantity}</span>
                <button type="button" onClick={() => updateQuantity(item.public_id, item.quantity + 1)}>
                  +
                </button>
              </div>
            </div>
            <div className="text-right">
              <p>{formatCents(item.unit_price_snapshot_cents * item.quantity)}</p>
              <button type="button" className="text-xs text-red-600" onClick={() => removeLine(item.public_id)}>
                Remove
              </button>
            </div>
          </li>
        ))}
      </ul>
      {pricing ? (
        <div className="mt-8 space-y-2 text-sm">
          <div className="flex justify-between">
            <span>Subtotal</span>
            <span>{formatCents(pricing.subtotal_cents)}</span>
          </div>
          <div className="flex justify-between text-lg font-semibold">
            <span>Total</span>
            <span>{formatCents(pricing.total_before_delivery_cents)}</span>
          </div>
        </div>
      ) : null}
      <Link href="/checkout" className="mt-8 block">
        <Button className="w-full" disabled={!pricing?.minimum_order_met || cart.accepting_orders === false}>
          Continue to checkout
        </Button>
      </Link>
    </main>
  );
}
