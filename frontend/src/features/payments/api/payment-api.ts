import { apiGet, apiRequest } from "@/lib/api/client";

export type PaymentSummary = {
  public_id: string;
  order_public_id?: string;
  order_number?: string;
  status: string;
  currency: string;
  amount_cents: number;
  amount_received_cents?: number;
  amount_refunded_cents?: number;
  platform_fee_cents?: number;
  restaurant_share_cents?: number;
  paid_at?: string | null;
  failed_at?: string | null;
  last_error_code?: string | null;
  client_secret?: string;
  publishable_key?: string;
};

export type AdminPaymentListItem = PaymentSummary & {
  provider?: string;
  external_payment_intent_id?: string | null;
  order_public_id?: string | null;
  order_number?: string | null;
  restaurant_public_id?: string | null;
  restaurant_name?: string | null;
  ownership_type?: string | null;
  created_at?: string | null;
};

export type AdminPaymentDetail = AdminPaymentListItem & {
  external_charge_id?: string | null;
  connected_account_id?: string | null;
  processing_fee_cents?: number;
  last_error_message?: string | null;
  metadata?: Record<string, unknown> | null;
  attempts?: Array<{
    public_id: string;
    attempt_number: number;
    status: string;
    external_payment_intent_id?: string | null;
    started_at?: string | null;
    completed_at?: string | null;
  }>;
  refunds?: Array<{
    public_id: string;
    status: string;
    amount_cents: number;
    reason_category: string;
    internal_note?: string | null;
    external_refund_id?: string | null;
    requested_at?: string | null;
    completed_at?: string | null;
  }>;
  disputes?: Array<{
    public_id: string;
    status: string;
    reason?: string | null;
    amount_cents: number;
    external_dispute_id?: string | null;
  }>;
};

export type RestaurantPaymentAccount = {
  ownership_type?: string;
  requires_connected_account?: boolean;
  online_payments_enabled?: boolean;
  message?: string;
  provider?: string;
  onboarding_status?: string;
  charges_enabled?: boolean;
  payouts_enabled?: boolean;
  details_submitted?: boolean;
  requirements_currently_due?: string[] | null;
  requirements_eventually_due?: string[] | null;
  disabled_reason?: string | null;
  country?: string | null;
  default_currency?: string | null;
  last_synced_at?: string | null;
  onboarding_completed_at?: string | null;
};

export type PaginatedMeta = {
  current_page?: number;
  last_page?: number;
  total?: number;
  per_page?: number;
};

function queryString(params: Record<string, string | number | boolean | undefined>): string {
  const qs = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== "") qs.set(key, String(value));
  }
  const s = qs.toString();
  return s ? `?${s}` : "";
}

export const customerPaymentApi = {
  getForOrder(publicId: string) {
    return apiGet<{ payment: PaymentSummary | null }>(`/api/v1/orders/${publicId}/payment`);
  },
  retry(publicId: string) {
    return apiRequest<{
      payment: PaymentSummary;
      client_secret?: string | null;
      publishable_key?: string | null;
    }>(`/api/v1/orders/${publicId}/payment/retry`, { method: "POST", body: {} });
  },
};

export const restaurantPaymentAccountApi = {
  get() {
    return apiGet<{ payment_account: RestaurantPaymentAccount }>("/api/v1/restaurant/payment-account");
  },
  create() {
    return apiRequest<{ payment_account: RestaurantPaymentAccount }>("/api/v1/restaurant/payment-account", {
      method: "POST",
      body: {},
    });
  },
  onboardingLink() {
    return apiRequest<{ url: string; expires_at?: string | null }>(
      "/api/v1/restaurant/payment-account/onboarding-link",
      { method: "POST", body: {} },
    );
  },
  refresh() {
    return apiRequest<{ payment_account: RestaurantPaymentAccount; onboarding_status?: string }>(
      "/api/v1/restaurant/payment-account/refresh",
      { method: "POST", body: {} },
    );
  },
};

export const restaurantPaymentApi = {
  summary(orderPublicId: string) {
    return apiGet<{ payment: PaymentSummary | null }>(
      `/api/v1/restaurant/orders/${orderPublicId}/payment-summary`,
    );
  },
  requestRefund(
    orderPublicId: string,
    body: {
      amount_cents: number;
      reason_category: string;
      customer_reason?: string;
      internal_note?: string;
      confirm: true;
      idempotency_key?: string;
    },
  ) {
    return apiRequest<{ refund: { public_id: string; status: string; amount_cents: number } }>(
      `/api/v1/restaurant/orders/${orderPublicId}/refund-requests`,
      { method: "POST", body },
    );
  },
};

export const adminPaymentApi = {
  list(params?: {
    status?: string;
    provider?: string;
    order_public_id?: string;
    restaurant_public_id?: string;
    external_payment_intent_id?: string;
    min_amount_cents?: number;
    max_amount_cents?: number;
    page?: number;
    per_page?: number;
  }) {
    return apiGet<{ payments: AdminPaymentListItem[] }>(
      `/api/v1/admin/payments${queryString(params ?? {})}`,
    );
  },
  get(publicId: string) {
    return apiGet<{ payment: AdminPaymentDetail; audit: Array<{ action: string; created_at?: string }> }>(
      `/api/v1/admin/payments/${publicId}`,
    );
  },
  createRefund(
    publicId: string,
    body: {
      amount_cents: number;
      reason_category: string;
      customer_reason?: string;
      internal_note?: string;
      confirm: true;
      idempotency_key?: string;
    },
  ) {
    return apiRequest<{ refund: { public_id: string; status: string; amount_cents: number } }>(
      `/api/v1/admin/payments/${publicId}/refunds`,
      { method: "POST", body },
    );
  },
};

export const adminRefundApi = {
  list(params?: { status?: string; restaurant_public_id?: string; page?: number; per_page?: number }) {
    return apiGet<{ refunds: Array<Record<string, unknown>> }>(
      `/api/v1/admin/refunds${queryString(params ?? {})}`,
    );
  },
  get(publicId: string) {
    return apiGet<{ refund: Record<string, unknown> }>(`/api/v1/admin/refunds/${publicId}`);
  },
};

export const adminDisputeApi = {
  list(params?: { status?: string; page?: number; per_page?: number }) {
    return apiGet<{ disputes: Array<Record<string, unknown>> }>(
      `/api/v1/admin/disputes${queryString(params ?? {})}`,
    );
  },
  get(publicId: string) {
    return apiGet<{ dispute: Record<string, unknown> }>(`/api/v1/admin/disputes/${publicId}`);
  },
};

export const adminWebhookApi = {
  retry(eventPublicId: string) {
    return apiRequest<{ event: { public_id: string; processing_status?: string } }>(
      `/api/v1/admin/payment-webhooks/${eventPublicId}/retry`,
      { method: "POST", body: {} },
    );
  },
};

export const adminPaymentAccountApi = {
  list(params?: { onboarding_status?: string; charges_enabled_only?: boolean; page?: number; per_page?: number }) {
    return apiRequest<{ payment_accounts: Array<Record<string, unknown>> }>(
      `/api/v1/admin/payment-accounts${queryString(params ?? {})}`,
    );
  },
  get(restaurantPublicId: string) {
    return apiGet<{ restaurant: Record<string, unknown>; payment_account: Record<string, unknown> }>(
      `/api/v1/admin/payment-accounts/${restaurantPublicId}`,
    );
  },
  refresh(restaurantPublicId: string) {
    return apiRequest<{ payment_account: Record<string, unknown> }>(
      `/api/v1/admin/payment-accounts/${restaurantPublicId}/refresh`,
      { method: "POST", body: {} },
    );
  },
  disableOnlinePayments(restaurantPublicId: string) {
    return apiRequest<{ payment_account: Record<string, unknown> }>(
      `/api/v1/admin/payment-accounts/${restaurantPublicId}/disable-online-payments`,
      { method: "POST", body: {} },
    );
  },
};
