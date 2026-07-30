const MESSAGES: Record<string, string> = {
  PAYMENT_CONFIGURATION_MISSING: "Payment provider is not configured.",
  PAYMENT_ACCOUNT_NOT_READY: "This restaurant is not ready to accept online payments.",
  PAYMENT_INTENT_CREATION_FAILED: "Unable to start payment. Please try again.",
  PAYMENT_ALREADY_PAID: "This order has already been paid.",
  PAYMENT_RETRY_NOT_ALLOWED: "Payment cannot be retried for this order.",
  PAYMENT_ATTEMPT_LIMIT_REACHED: "Maximum payment attempts reached for this order.",
  PAYMENT_AMOUNT_MISMATCH: "Payment amount does not match the order total.",
  PAYMENT_CURRENCY_MISMATCH: "Payment currency does not match the order currency.",
  PAYMENT_REQUIRES_ACTION: "Additional authentication is required to complete payment.",
  PAYMENT_PROCESSING: "Payment is still processing.",
  PAYMENT_FAILED: "Payment failed.",
  PAYMENT_CANCELLED: "Payment was cancelled.",
  INVALID_WEBHOOK_SIGNATURE: "Webhook signature verification failed.",
  WEBHOOK_ALREADY_PROCESSED: "This webhook event has already been processed.",
  WEBHOOK_PROCESSING_FAILED: "Webhook event processing failed.",
  REFUND_AMOUNT_INVALID: "Refund amount is invalid.",
  REFUND_EXCEEDS_AVAILABLE_AMOUNT: "Refund amount exceeds the available refundable balance.",
  REFUND_NOT_ALLOWED: "A refund is not allowed for this payment.",
  REFUND_ALREADY_PROCESSED: "This refund has already been processed.",
  CONNECTED_ACCOUNT_RESTRICTED: "The restaurant payment account is restricted.",
};

export function paymentErrorMessage(code: string | null | undefined, fallback: string): string {
  if (code && MESSAGES[code]) return MESSAGES[code];
  return fallback;
}
