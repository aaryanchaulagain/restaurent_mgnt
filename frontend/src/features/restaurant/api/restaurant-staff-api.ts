import { apiGet, apiRequest } from "@/lib/api/client";

export type RestaurantStaffMember = {
  user_id: number;
  email: string | null;
  name: string | null;
  first_name?: string | null;
  last_name?: string | null;
  role: string | null;
  status: string;
  joined_at: string | null;
};

export const restaurantStaffApi = {
  list() {
    return apiGet<{ staff: RestaurantStaffMember[] }>("/api/v1/restaurant/staff");
  },

  invite(body: {
    first_name: string;
    last_name: string;
    email: string;
    phone?: string;
    role: "restaurant_manager" | "restaurant_staff";
  }) {
    return apiRequest<{
      member: RestaurantStaffMember;
    }>("/api/v1/restaurant/staff", { method: "POST", body });
  },

  updateRole(userId: number, role: "restaurant_manager" | "restaurant_staff") {
    return apiRequest<{ member: RestaurantStaffMember }>(
      `/api/v1/restaurant/staff/${userId}`,
      { method: "PATCH", body: { role } },
    );
  },

  revoke(userId: number) {
    return apiRequest(`/api/v1/restaurant/staff/${userId}`, { method: "DELETE" });
  },
};
