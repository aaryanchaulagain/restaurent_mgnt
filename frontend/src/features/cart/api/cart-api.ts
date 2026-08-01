import { apiGet, apiRequest } from "@/lib/api/client";

export type ImageUrls = {
  thumbnail_url: string;
  card_url: string;
  large_url: string;
  original_url: string;
};

export type PublicMenuItem = {
  public_id: string;
  menu_category_public_id: string;
  name: string;
  slug: string;
  short_description?: string | null;
  description?: string | null;
  image: ImageUrls;
  base_price_cents: number;
  compare_at_price_cents?: number | null;
  preparation_minutes?: number | null;
  is_available: boolean;
  availability_message?: string | null;
  type_details?: Record<string, unknown> | null;
  dietary: {
    is_vegetarian: boolean;
    is_vegan: boolean;
    is_gluten_free: boolean;
    is_halal: boolean;
  };
  variants: Array<{
    public_id: string;
    name: string;
    price_cents: number;
    is_default: boolean;
  }>;
  modifier_groups: Array<{
    public_id: string;
    name: string;
    selection_type: "single" | "multiple";
    minimum_selections: number;
    maximum_selections: number;
    is_required: boolean;
    options: Array<{
      public_id: string;
      name: string;
      price_adjustment_cents: number;
      is_default: boolean;
    }>;
  }>;
  allergens: Array<{ slug: string; name: string; presence_type: string }>;
};

export type PublicRestaurantDetail = {
  public_id: string;
  slug: string;
  trading_name: string;
  short_description?: string | null;
  description?: string | null;
  logo: ImageUrls;
  cover: ImageUrls;
  currency: string;
  minimum_order_cents?: number;
  average_preparation_minutes?: number;
  pickup_enabled: boolean;
  restaurant_delivery_enabled: boolean;
  third_party_delivery_enabled: boolean;
  accepting_orders: boolean;
  is_open: boolean;
  vendor_type?: string | null;
  business_type?: string | null;
  today_hours?: Array<{ opens_at: string; closes_at: string; service_type: string }> | null;
  address_summary?: { suburb: string; state: string; postcode: string; address_line_1: string } | null;
  cuisines?: Array<{ name: string; slug: string; is_primary: boolean }>;
};

export const publicRestaurantApi = {
  getRestaurant(slug: string) {
    return apiGet<{ restaurant: PublicRestaurantDetail; active_offers: unknown[]; allergen_disclaimer: string }>(
      `/api/v1/public/restaurants/${slug}`,
    );
  },
  getMenu(slug: string) {
    return apiGet<{
      categories: Array<{ public_id: string; name: string; description?: string | null }>;
      items: PublicMenuItem[];
    }>(`/api/v1/public/restaurants/${slug}/menu`);
  },
};

export type CartPricing = {
  subtotal_cents: number;
  discount_cents: number;
  tax_cents: number;
  service_fee_cents: number;
  delivery_fee_cents: number | null;
  total_before_delivery_cents: number;
  currency: string;
  minimum_order_cents: number;
  minimum_order_met: boolean;
  warnings: Array<{ code: string; message: string; cart_item_public_id?: string }>;
};

export type CartState = {
  public_id: string;
  version: number;
  currency: string;
  restaurant: { slug: string; trading_name: string; minimum_order_cents?: number };
  items: Array<{
    public_id: string;
    quantity: number;
    name?: string;
    unit_price_snapshot_cents: number;
    special_instructions?: string | null;
  }>;
};

export const cartApi = {
  getCart() {
    return apiGet<{ cart: CartState | null; pricing: CartPricing | null }>("/api/v1/cart");
  },
  addItem(body: {
    menu_item_public_id: string;
    variant_public_id?: string;
    quantity: number;
    modifier_option_public_ids?: string[];
    special_instructions?: string;
    replace_restaurant?: boolean;
  }) {
    return apiRequest<{ cart: CartState; pricing: CartPricing }>("/api/v1/cart/items", {
      method: "POST",
      body,
    });
  },
  updateItem(publicId: string, body: { quantity?: number }) {
    return apiRequest<{ cart: CartState; pricing: CartPricing }>(`/api/v1/cart/items/${publicId}`, {
      method: "PATCH",
      body,
    });
  },
  removeItem(publicId: string) {
    return apiRequest<{ cart: CartState; pricing: CartPricing }>(`/api/v1/cart/items/${publicId}`, {
      method: "DELETE",
    });
  },
  clearCart() {
    return apiRequest("/api/v1/cart", { method: "DELETE" });
  },
  replaceRestaurant(restaurantSlug: string) {
    return apiRequest("/api/v1/cart/replace-restaurant", {
      method: "POST",
      body: { restaurant_slug: restaurantSlug },
    });
  },
  validateCart() {
    return apiRequest<{ cart: CartState; pricing: CartPricing }>("/api/v1/cart/validate", { method: "POST" });
  },
  createQuote(body: Record<string, unknown>) {
    return apiRequest("/api/v1/checkout/quote", { method: "POST", body });
  },
};
