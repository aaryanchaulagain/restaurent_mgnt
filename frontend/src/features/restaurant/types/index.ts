export type RestaurantProfile = {
  public_id: string;
  slug: string;
  trading_name: string;
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
