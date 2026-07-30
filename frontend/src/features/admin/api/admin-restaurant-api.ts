import { apiGet, apiRequest } from "@/lib/api/client";

export type AdminRestaurantListItem = {
  public_id: string;
  slug: string;
  trading_name: string;
  legal_business_name: string;
  business_email: string | null;
  status: string;
  ownership_type: string;
  accepting_orders: boolean;
  published_at: string | null;
  active_staff_count: number;
  commission_rate: string | number | null;
};

export type AdminRestaurantMember = {
  user_id: number;
  email: string | null;
  name: string | null;
  role: string | null;
  status: string;
  joined_at: string | null;
};

export type AdminRestaurantDetail = AdminRestaurantListItem & {
  description: string | null;
  short_description: string | null;
  business_phone: string | null;
  timezone: string | null;
  currency: string | null;
  suspended_at: string | null;
  suspension_reason: string | null;
  temporarily_closed_reason: string | null;
  owners: AdminRestaurantMember[];
  commission_agreements: Array<{
    id: number;
    commission_type: string;
    commission_rate: string | number | null;
    status: string;
    effective_from: string | null;
  }>;
};

export type ProvisionPayload = {
  trading_name: string;
  legal_business_name: string;
  business_email?: string;
  business_phone?: string;
  description?: string;
  ownership_type?: "first_party" | "third_party";
  activate_now?: boolean;
  commission_rate?: number;
  owner: {
    first_name: string;
    last_name: string;
    email: string;
    password?: string;
    phone?: string;
  };
};

function qs(params?: Record<string, string | number | undefined>) {
  if (!params) return "";
  const search = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== "") search.set(k, String(v));
  });
  const s = search.toString();
  return s ? `?${s}` : "";
}

export const adminRestaurantApi = {
  list(params?: {
    status?: string;
    ownership_type?: string;
    q?: string;
    page?: number;
    per_page?: number;
  }) {
    return apiGet<{ restaurants: AdminRestaurantListItem[] }>(
      `/api/v1/admin/restaurants${qs(params)}`,
    );
  },

  show(publicId: string) {
    return apiGet<{ restaurant: AdminRestaurantDetail }>(
      `/api/v1/admin/restaurants/${publicId}`,
    );
  },

  provision(payload: ProvisionPayload) {
    return apiRequest<{
      restaurant: AdminRestaurantDetail;
      owner: { id: number; email: string; name: string };
      temporary_password: string | null;
    }>("/api/v1/admin/restaurants/provision", {
      method: "POST",
      body: payload,
    });
  },

  update(publicId: string, body: Record<string, unknown>) {
    return apiRequest<{ restaurant: AdminRestaurantDetail }>(
      `/api/v1/admin/restaurants/${publicId}`,
      { method: "PATCH", body },
    );
  },

  remove(publicId: string) {
    return apiRequest<{
      restaurant: { public_id: string; status: string; deleted: boolean };
    }>(`/api/v1/admin/restaurants/${publicId}`, { method: "DELETE" });
  },

  addOwner(
    publicId: string,
    body: {
      first_name?: string;
      last_name?: string;
      email?: string;
      password?: string;
      phone?: string;
      user_id?: number;
      role?: "restaurant_owner" | "restaurant_manager";
    },
  ) {
    return apiRequest<{
      member: AdminRestaurantMember;
      temporary_password: string | null;
    }>(`/api/v1/admin/restaurants/${publicId}/owners`, {
      method: "POST",
      body,
    });
  },

  removeOwner(publicId: string, userId: number) {
    return apiRequest(`/api/v1/admin/restaurants/${publicId}/owners/${userId}`, {
      method: "DELETE",
    });
  },
};
