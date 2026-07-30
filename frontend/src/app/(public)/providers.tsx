"use client";

import { CartProvider } from "@/features/cart/components/cart-provider";
import { CartConflictModal } from "@/features/cart/components/cart-conflict-modal";

export function PublicProviders({ children }: { children: React.ReactNode }) {
  return (
    <CartProvider>
      {children}
      <CartConflictModal />
    </CartProvider>
  );
}
