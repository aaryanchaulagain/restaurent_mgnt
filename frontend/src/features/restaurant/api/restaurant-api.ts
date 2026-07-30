import { apiFormData, apiGet, apiRequest } from "@/lib/api/client";
import type { RestaurantChecklist, RestaurantProfile } from "../types";

type ProfilePayload = { profile: RestaurantProfile };
type ChecklistPayload = RestaurantChecklist;

export const restaurantApi = {
  getProfile() {
    return apiGet<ProfilePayload>("/api/v1/restaurant/profile");
  },

  updateProfile(payload: Partial<RestaurantProfile>) {
    return apiRequest<ProfilePayload>("/api/v1/restaurant/profile", {
      method: "PATCH",
      body: payload,
    });
  },

  getChecklist() {
    return apiGet<ChecklistPayload>("/api/v1/restaurant/profile/checklist");
  },

  activate() {
    return apiRequest<{ profile: RestaurantProfile; checklist: RestaurantChecklist }>(
      "/api/v1/restaurant/profile/activate",
      { method: "POST" },
    );
  },

  uploadLogo(file: File) {
    const form = new FormData();
    form.append("file", file);
    return apiFormData<{ logo_path: string }>("/api/v1/restaurant/media/logo", form);
  },

  uploadCover(file: File) {
    const form = new FormData();
    form.append("file", file);
    return apiFormData<{ cover_image_path: string }>("/api/v1/restaurant/media/cover", form);
  },

  listCategories() {
    return apiGet<{ categories: MenuCategory[] }>("/api/v1/restaurant/menu-categories");
  },

  listMenuItems(params?: { category_public_id?: string }) {
    const qs = params?.category_public_id
      ? `?category_public_id=${encodeURIComponent(params.category_public_id)}`
      : "";
    return apiGet<{ items: MenuItem[] }>(`/api/v1/restaurant/menu-items${qs}`);
  },
};

export type MenuCategory = {
  public_id: string;
  name: string;
  description?: string | null;
  is_active: boolean;
  sort_order: number;
};

export type MenuItem = {
  public_id: string;
  name: string;
  slug: string;
  short_description?: string | null;
  base_price_cents: number;
  is_active: boolean;
  is_available: boolean;
};

export const publicRestaurantApi = {
  listRestaurants(params?: Record<string, string | undefined>) {
    const search = new URLSearchParams();
    for (const [key, value] of Object.entries(params ?? {})) {
      if (value) search.set(key, value);
    }
    const qs = search.toString();
    return apiGet<{ restaurants: PublicRestaurant[] }>(
      `/api/v1/public/restaurants${qs ? `?${qs}` : ""}`,
    );
  },

  listCuisines() {
    return apiGet<{ cuisines: Array<{ name: string; slug: string }> }>(
      "/api/v1/public/cuisines",
    );
  },

  getRestaurant(slug: string) {
    return apiGet<{ restaurant: PublicRestaurant; hours: unknown[]; offers: unknown[] }>(
      `/api/v1/public/restaurants/${slug}`,
    );
  },

  getMenu(slug: string) {
    return apiGet<{ categories: MenuCategory[]; items: MenuItem[] }>(
      `/api/v1/public/restaurants/${slug}/menu`,
    );
  },
};

export type PublicRestaurant = {
  slug: string;
  trading_name: string;
  short_description?: string | null;
  description?: string | null;
  price_level?: string | null;
  logo_path?: string | null;
  cover_image_path?: string | null;
  currency: string;
  minimum_order_cents?: number;
  open_now?: boolean;
  is_open?: boolean;
  pickup_enabled: boolean;
  restaurant_delivery_enabled: boolean;
  is_platform_restaurant?: boolean;
  cuisines?: Array<{ name: string; slug: string; is_primary?: boolean }>;
};
