import type { CartState } from "../api/cart-api";

/** Human-readable cart lock label for headers and checkout. */
export function cartBranchLabel(cart: CartState | null | undefined): string {
  if (!cart) return "";
  const branch = cart.branch?.name;
  const business = cart.business?.name;
  if (business && branch) return `${business} — ${branch}`;
  if (branch) return branch;
  return cart.restaurant.trading_name;
}

export function cartLocality(cart: CartState | null | undefined): string | null {
  if (!cart?.branch) return null;
  const parts = [cart.branch.city, cart.branch.state].filter(Boolean);
  return parts.length ? parts.join(", ") : null;
}
