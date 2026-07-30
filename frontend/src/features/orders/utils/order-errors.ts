const MESSAGES: Record<string, string> = {
  IDEMPOTENCY_KEY_REUSED:
    "This checkout was already submitted with different details. Refresh checkout and try again.",
  CHECKOUT_QUOTE_EXPIRED: "Your checkout quote expired. Please prepare a new quote.",
  CART_CHANGED: "Your cart changed. Refresh checkout before placing the order.",
  PRICE_CHANGED: "Prices changed. Refresh checkout to review the new total.",
  INVALID_ORDER_TRANSITION: "That action is not available for this order anymore.",
  ORDER_CANCELLATION_NOT_ALLOWED: "This order can no longer be cancelled online.",
};

export function orderErrorMessage(code: string | null | undefined, fallback: string): string {
  if (code && MESSAGES[code]) return MESSAGES[code];
  return fallback;
}
