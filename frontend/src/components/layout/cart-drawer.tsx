"use client";

import Link from "next/link";
import { Button } from "@/components/ui/button";
import { Drawer } from "@/components/ui/overlay";
import { useCart } from "@/features/cart/components/cart-provider";
import { cartBranchLabel, cartLocality } from "@/features/cart/lib/cart-branch-label";
import { formatCents } from "@/lib/utils";

export function CartDrawer({
  open,
  onClose,
}: {
  open: boolean;
  onClose: () => void;
}) {
  const { cart, pricing, isLoading } = useCart();
  const locality = cartLocality(cart);

  return (
    <Drawer open={open} onClose={onClose} title="Your cart">
      {isLoading ? (
        <p className="text-sm text-[var(--text-muted)]">Loading cart…</p>
      ) : !cart ? (
        <p className="text-sm text-[var(--text-secondary)]">Your cart is empty.</p>
      ) : (
        <>
          <p className="text-sm text-[var(--text-secondary)]">
            Your current cart is from <strong>{cartBranchLabel(cart)}</strong>
            {locality ? ` (${locality})` : ""}.
          </p>
          <ul className="mt-5 space-y-4">
            {cart.items.map((item) => (
              <li key={item.public_id} className="border-b border-[var(--border-subtle)] pb-4 last:border-0">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <p className="font-medium">{item.name}</p>
                    <p className="mt-1 text-xs text-[var(--text-muted)]">Qty {item.quantity}</p>
                  </div>
                  <p className="text-sm font-semibold">
                    {formatCents(item.unit_price_snapshot_cents * item.quantity)}
                  </p>
                </div>
              </li>
            ))}
          </ul>
          {pricing ? (
            <div className="mt-6 space-y-2 text-sm">
              <div className="flex justify-between text-[var(--text-secondary)]">
                <span>Subtotal</span>
                <span>{formatCents(pricing.subtotal_cents)}</span>
              </div>
              {pricing.discount_cents ? (
                <div className="flex justify-between text-[var(--text-secondary)]">
                  <span>Discount</span>
                  <span>-{formatCents(pricing.discount_cents)}</span>
                </div>
              ) : null}
              <div className="flex justify-between border-t border-[var(--border-subtle)] pt-3 text-base font-semibold">
                <span>Total</span>
                <span>{formatCents(pricing.total_before_delivery_cents)}</span>
              </div>
              {!pricing.minimum_order_met ? (
                <p className="text-xs text-amber-700">Minimum order not met yet.</p>
              ) : null}
            </div>
          ) : null}
          <div className="mt-6 flex flex-col gap-3">
            <Link href="/checkout" onClick={onClose}>
              <Button className="w-full" disabled={!pricing?.minimum_order_met || cart.accepting_orders === false}>
                Checkout
              </Button>
            </Link>
            <Link href="/cart" onClick={onClose} className="text-center text-sm font-medium text-[var(--color-burnt-orange)]">
              View full cart
            </Link>
          </div>
        </>
      )}
    </Drawer>
  );
}
