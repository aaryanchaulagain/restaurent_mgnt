import { apiGet, apiRequest } from "@/lib/api/client";

export type OrderItem = {
  public_id: string;
  name: string;
  description?: string | null;
  variant?: string | null;
  unit_price_cents: number;
  quantity: number;
  line_total_cents: number;
  dietary?: Record<string, boolean> | null;
  allergens?: Array<{ name: string; slug: string }> | null;
  instructions?: string | null;
  modifiers: Array<{ group: string; option: string; price_adjustment_cents: number }>;
};

export type OrderTimeline = {
  from: string | null;
  to: string;
  actor: string;
  at: string;
  reason?: string | null;
};

export type OrderDetail = {
  public_id: string;
  order_number: string;
  status: string;
  payment_method: string;
  payment_status: string;
  fulfilment_type: string;
  currency: string;
  customer_name?: string | null;
  customer_email?: string | null;
  customer_phone?: string | null;
  subtotal_cents: number;
  discount_cents: number;
  tax_cents: number;
  service_fee_cents: number;
  delivery_fee_cents: number;
  total_cents: number;
  placed_at?: string | null;
  accepted_at?: string | null;
  preparing_at?: string | null;
  ready_at?: string | null;
  completed_at?: string | null;
  cancelled_at?: string | null;
  estimated_ready_at?: string | null;
  rejection_reason?: string | null;
  rejection_explanation?: string | null;
  cancellation_reason?: string | null;
  delivery_address?: Record<string, unknown> | null;
  pickup_instructions?: string | null;
  customer_notes?: string | null;
  items: OrderItem[];
  adjustments: Array<{ type: string; label: string; amount_cents: number }>;
  timeline: OrderTimeline[];
  guest_access_token?: string;
};

export const customerOrderApi = {
  place(body: {
    checkout_quote_public_id: string;
    idempotency_key: string;
    payment_method?: string;
    customer_name?: string;
    customer_email?: string;
    customer_phone?: string;
    pickup_instructions?: string;
    delivery_instructions?: string;
    customer_notes?: string;
    contactless_delivery?: boolean;
  }) {
    return apiRequest<{ order: OrderDetail }>("/api/v1/orders", { method: "POST", body });
  },
  list() {
    return apiGet<{ orders: OrderDetail[] }>("/api/v1/orders");
  },
  get(publicId: string) {
    return apiGet<{ order: OrderDetail }>(`/api/v1/orders/${publicId}`);
  },
  cancel(publicId: string, reason?: string) {
    return apiRequest(`/api/v1/orders/${publicId}/cancel`, { method: "POST", body: { reason } });
  },
  guestTrack(orderNumber: string, token: string) {
    return apiGet<{ order: OrderDetail }>(`/api/v1/guest/orders/${orderNumber}?token=${encodeURIComponent(token)}`);
  },
};

export const restaurantOrderApi = {
  list(status?: string) {
    const qs = status ? `?status=${status}` : "";
    return apiGet<{ orders: OrderDetail[] }>(`/api/v1/restaurant/orders${qs}`);
  },
  get(publicId: string) {
    return apiGet<{ order: OrderDetail }>(`/api/v1/restaurant/orders/${publicId}`);
  },
  accept(publicId: string, estimatedMinutes?: number) {
    return apiRequest(`/api/v1/restaurant/orders/${publicId}/accept`, {
      method: "POST",
      body: estimatedMinutes ? { estimated_ready_minutes: estimatedMinutes } : {},
    });
  },
  reject(publicId: string, reason: string, explanation?: string) {
    return apiRequest(`/api/v1/restaurant/orders/${publicId}/reject`, {
      method: "POST",
      body: { reason, explanation },
    });
  },
  startPreparing(publicId: string) {
    return apiRequest(`/api/v1/restaurant/orders/${publicId}/start-preparing`, { method: "POST" });
  },
  markReady(publicId: string) {
    return apiRequest(`/api/v1/restaurant/orders/${publicId}/mark-ready`, { method: "POST" });
  },
  completePickup(publicId: string) {
    return apiRequest(`/api/v1/restaurant/orders/${publicId}/complete-pickup`, { method: "POST" });
  },
  cancel(publicId: string, reason?: string) {
    return apiRequest(`/api/v1/restaurant/orders/${publicId}/cancel`, { method: "POST", body: { reason } });
  },
};
