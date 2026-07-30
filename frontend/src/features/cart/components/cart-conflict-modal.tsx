"use client";

import { ConfirmDialog } from "@/components/ui/overlay";
import { useCart } from "./cart-provider";

export function CartConflictModal() {
  const { conflict, clearConflict, confirmReplaceRestaurant } = useCart();

  return (
    <ConfirmDialog
      open={conflict.open}
      onClose={clearConflict}
      title="Start a new order?"
      description="Your cart contains items from another restaurant. Starting a new order will clear your current cart."
      cancelLabel="Keep current cart"
      confirmLabel="Clear and start new order"
      onConfirm={() => void confirmReplaceRestaurant()}
    />
  );
}
