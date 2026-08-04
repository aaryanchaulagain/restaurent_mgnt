"use client";

import { ConfirmDialog } from "@/components/ui/overlay";
import { useCart } from "./cart-provider";

export function CartConflictModal() {
  const { conflict, clearConflict, confirmReplaceRestaurant } = useCart();

  const currentName =
    conflict.data?.current_cart?.branch_name ??
    conflict.data?.current_restaurant?.trading_name ??
    "another location";
  const requestedName =
    conflict.data?.requested_branch?.branch_name ??
    conflict.data?.requested_restaurant?.trading_name ??
    "this location";

  return (
    <ConfirmDialog
      open={conflict.open}
      onClose={clearConflict}
      title="Switch branch?"
      description={`Your cart contains items from ${currentName}. To order from ${requestedName}, clear your current cart.`}
      cancelLabel="Keep current cart"
      confirmLabel="Clear cart and switch"
      onConfirm={() => void confirmReplaceRestaurant()}
    />
  );
}
