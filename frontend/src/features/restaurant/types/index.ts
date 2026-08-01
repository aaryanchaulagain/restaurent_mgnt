export type RestaurantProfile = {
  public_id: string;
  slug: string;
  trading_name: string;
  /** Normalized vertical from businesses.business_type (or vendor_type fallback). */
  business_type?: string | null;
  /** Legacy restaurants.vendor_type (may use butchery). */
  vendor_type?: string | null;
  catalogue?: {
    type: string;
    label: string;
    portal_label: string;
    catalogue_label: string;
    category_label: string;
    product_label: string;
    product_plural_label: string;
    add_product_label: string;
    supports_variants: boolean;
    supports_modifiers: boolean;
    supports_dietary: boolean;
    supports_preparation_time: boolean;
    supports_cuisine: boolean;
    default_categories: string[];
  };
  short_description?: string | null;
  description?: string | null;
  business_email?: string | null;
  business_phone?: string | null;
  website_url?: string | null;
  status: string;
  price_level?: string | null;
  logo_path?: string | null;
  cover_image_path?: string | null;
  timezone?: string;
  currency?: string;
  minimum_order_cents?: number;
  average_preparation_minutes?: number;
  pickup_enabled: boolean;
  restaurant_delivery_enabled: boolean;
  dine_in_enabled: boolean;
  accepting_orders: boolean;
  published_at?: string | null;
  primary_address?: {
    address_line_1: string;
    address_line_2?: string | null;
    suburb: string;
    state: string;
    postcode: string;
    country?: string;
  } | null;
};

export type RestaurantChecklist = {
  completion_percentage: number;
  can_activate: boolean;
  completed: string[];
  missing: string[];
};
