import { apiFormData, apiGet, apiRequest } from "@/lib/api/client";

export type OpeningHourRow = {
  id?: number;
  day_of_week: number;
  opens_at: string | null;
  closes_at: string | null;
  is_closed: boolean;
  service_type: "all" | "pickup" | "restaurant_delivery";
};

export type SpecialHourRow = {
  id: number;
  date: string;
  opens_at: string | null;
  closes_at: string | null;
  is_closed: boolean;
  reason?: string | null;
};

export const restaurantHoursApi = {
  getHours() {
    return apiGet<{ hours: OpeningHourRow[] }>("/api/v1/restaurant/hours");
  },
  saveHours(hours: OpeningHourRow[]) {
    return apiRequest<{ hours: OpeningHourRow[] }>("/api/v1/restaurant/hours", {
      method: "PUT",
      body: { hours },
    });
  },
  getPreview() {
    return apiGet<{
      timezone: string;
      is_open: boolean;
      is_open_pickup: boolean;
      is_open_delivery: boolean;
      next_opening_time: string | null;
    }>("/api/v1/restaurant/hours/preview");
  },
  listSpecial() {
    return apiGet<{ special_hours: SpecialHourRow[] }>("/api/v1/restaurant/special-hours");
  },
  createSpecial(body: Omit<SpecialHourRow, "id">) {
    return apiRequest("/api/v1/restaurant/special-hours", { method: "POST", body });
  },
  deleteSpecial(id: number) {
    return apiRequest(`/api/v1/restaurant/special-hours/${id}`, { method: "DELETE" });
  },
};

export type AdminMenuItem = {
  public_id: string;
  name: string;
  slug: string;
  base_price_cents: number;
  compare_at_price_cents?: number | null;
  cost_price_cents?: number;
  is_active: boolean;
  is_available: boolean;
  is_featured?: boolean;
  short_description?: string | null;
  description?: string | null;
  image?: { card_url: string; original_url: string; thumbnail_url: string; large_url: string };
  variants?: unknown[];
  allergens?: unknown[];
};

export const restaurantMenuAdminApi = {
  listMenus() {
    return apiGet<{ menus: Array<{ public_id: string; name: string; status: string; is_default: boolean }> }>(
      "/api/v1/restaurant/menus",
    );
  },
  listCategories() {
    return apiGet<{ categories: Array<{ public_id: string; name: string; is_active: boolean; sort_order: number }> }>(
      "/api/v1/restaurant/menu-categories",
    );
  },
  listItems(params?: Record<string, string>) {
    const qs = new URLSearchParams(params ?? "").toString();
    return apiGet<{ items: AdminMenuItem[] }>(`/api/v1/restaurant/menu-items${qs ? `?${qs}` : ""}`);
  },
  getItem(publicId: string) {
    return apiGet<{ item: AdminMenuItem }>(`/api/v1/restaurant/menu-items/${publicId}`);
  },
  createItem(body: Record<string, unknown>) {
    return apiRequest("/api/v1/restaurant/menu-items", { method: "POST", body });
  },
  uploadItemImage(publicId: string, file: File) {
    const form = new FormData();
    form.append("file", file);
    return apiFormData<{
      item: AdminMenuItem;
      image: { card_url: string; original_url: string; thumbnail_url: string; large_url: string };
    }>(`/api/v1/restaurant/menu-items/${publicId}/image`, form);
  },
  updateItem(publicId: string, body: Record<string, unknown>) {
    return apiRequest(`/api/v1/restaurant/menu-items/${publicId}`, { method: "PATCH", body });
  },
  duplicateItem(publicId: string) {
    return apiRequest(`/api/v1/restaurant/menu-items/${publicId}/duplicate`, { method: "POST" });
  },
  bulk(action: string, itemPublicIds: string[], extra?: Record<string, unknown>) {
    return apiRequest("/api/v1/restaurant/menu-items/bulk", {
      method: "POST",
      body: { action, item_public_ids: itemPublicIds, ...extra },
    });
  },
  syncVariants(publicId: string, variants: unknown[]) {
    return apiRequest(`/api/v1/restaurant/menu-items/${publicId}/variants`, {
      method: "PUT",
      body: { variants },
    });
  },
  listModifierGroups() {
    return apiGet<{ modifier_groups: unknown[] }>("/api/v1/restaurant/modifier-groups");
  },
  createModifierGroup(body: Record<string, unknown>) {
    return apiRequest("/api/v1/restaurant/modifier-groups", { method: "POST", body });
  },
  createModifierOption(groupPublicId: string, body: Record<string, unknown>) {
    return apiRequest(`/api/v1/restaurant/modifier-groups/${groupPublicId}/options`, { method: "POST", body });
  },
  syncModifierGroups(itemPublicId: string, modifierGroupPublicIds: string[]) {
    return apiRequest(`/api/v1/restaurant/menu-items/${itemPublicId}/modifier-groups`, {
      method: "PUT",
      body: { modifier_group_public_ids: modifierGroupPublicIds },
    });
  },
  createCategory(body: Record<string, unknown>) {
    return apiRequest<{ category: { public_id: string; name: string; is_active: boolean; sort_order: number } }>(
      "/api/v1/restaurant/menu-categories",
      { method: "POST", body },
    );
  },
  updateCategory(publicId: string, body: Record<string, unknown>) {
    return apiRequest(`/api/v1/restaurant/menu-categories/${publicId}`, { method: "PATCH", body });
  },
  deleteCategory(publicId: string) {
    return apiRequest(`/api/v1/restaurant/menu-categories/${publicId}`, { method: "DELETE" });
  },
  reorderCategories(order: string[]) {
    return apiRequest("/api/v1/restaurant/menu-categories/reorder", { method: "POST", body: { order } });
  },
  updateAvailability(publicId: string, action: string) {
    return apiRequest(`/api/v1/restaurant/menu-items/${publicId}/availability`, { method: "POST", body: { action } });
  },
};

export type OfferTargetInput = { target_type: string; target_id: number };

export const restaurantOffersApi = {
  list() {
    return apiGet<{ offers: unknown[] }>("/api/v1/restaurant/offers");
  },
  create(body: Record<string, unknown>) {
    return apiRequest("/api/v1/restaurant/offers", { method: "POST", body });
  },
  update(publicId: string, body: Record<string, unknown>) {
    return apiRequest(`/api/v1/restaurant/offers/${publicId}`, { method: "PATCH", body });
  },
  get(publicId: string) {
    return apiGet<{ offer: unknown }>(`/api/v1/restaurant/offers/${publicId}`);
  },
  remove(publicId: string) {
    return apiRequest(`/api/v1/restaurant/offers/${publicId}`, { method: "DELETE" });
  },
};
