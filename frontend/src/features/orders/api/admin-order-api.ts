import { apiGet } from "@/lib/api/client";
import type { OrderDetail } from "@/features/orders/api/order-api";

export type AdminOrderListItem = OrderDetail & {
  is_guest?: boolean;
  commission_rate_snapshot?: number;
  commission_amount_cents?: number;
  restaurant?: {
    public_id: string;
    trading_name: string;
    ownership_type: string;
    slug?: string;
    soft_deleted?: boolean;
  } | null;
  relationship?: {
    state: string;
    warning: string | null;
    branch_public_id: string | null;
    business_public_id: string | null;
  };
};

export const adminOrderApi = {
  list(params: Record<string, string | number | boolean | undefined>) {
    const qs = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== "") qs.set(k, String(v));
    });
    const query = qs.toString();
    return apiGet<{ orders: AdminOrderListItem[] }>(`/api/v1/admin/orders${query ? `?${query}` : ""}`);
  },
  get(publicId: string) {
    return apiGet<{ order: AdminOrderListItem; audit: Array<{ action: string; created_at?: string }> }>(
      `/api/v1/admin/orders/${publicId}`,
    );
  },
};

export function ownershipLabel(type?: string): string {
  if (type === "first_party") return "Khana-operated";
  return "Partner restaurant";
}
