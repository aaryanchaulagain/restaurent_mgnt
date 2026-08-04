import { apiGet } from "@/lib/api/client";
import type { PublicMenuItem } from "@/features/cart/api/cart-api";

export type PublicBusinessDto = {
  public_id: string;
  slug: string;
  name: string;
  description?: string | null;
  business_type: string;
  ownership_type: string;
  status: string;
};

export type PublicBranchDto = {
  public_id: string;
  name: string;
  status: string;
  status_label: string;
  is_default: boolean;
  is_temporarily_closed: boolean;
  accepting_orders: boolean;
  is_open_now: boolean;
  next_opening_time?: string | null;
  today_hours?: Array<{ opens_at: string; closes_at: string; service_type: string }> | null;
  timezone?: string | null;
  address: {
    address_line?: string | null;
    city?: string | null;
    state?: string | null;
    postcode?: string | null;
    country?: string | null;
  };
  capabilities: {
    pickup_enabled: boolean;
    delivery_enabled: boolean;
    third_party_delivery_enabled: boolean;
  };
  minimum_order_amount_cents?: number | null;
  restaurant: {
    public_id: string;
    slug: string;
    trading_name: string;
    status: string;
    accepting_orders: boolean;
    business_type?: string | null;
  } | null;
};

export type PublicBusinessShowResponse = {
  business: PublicBusinessDto;
  branches: PublicBranchDto[];
  preferred_branch_public_id: string | null;
  branch_count: number;
};

export type PublicBranchShowResponse = {
  business: PublicBusinessDto;
  branch: PublicBranchDto;
  catalogue_restaurant_slug: string;
};

export type PublicBranchMenuResponse = {
  business: { slug: string; name: string };
  branch: { public_id: string; name: string };
  restaurant: { slug: string; public_id: string };
  categories: Array<{ public_id: string; name: string; description?: string | null }>;
  items: PublicMenuItem[];
  menus: unknown[];
};

export const publicBusinessQueryKeys = {
  business: (slug: string) => ["public-business", slug] as const,
  branches: (slug: string) => ["public-business-branches", slug] as const,
  branch: (slug: string, branchPublicId: string) =>
    ["public-business-branch", slug, branchPublicId] as const,
  menu: (slug: string, branchPublicId: string) =>
    ["public-business-branch-menu", slug, branchPublicId] as const,
};

export const publicBusinessApi = {
  getBusiness(businessSlug: string) {
    return apiGet<PublicBusinessShowResponse>(`/api/v1/public/businesses/${businessSlug}`);
  },
  listBranches(businessSlug: string) {
    return apiGet<{ business: PublicBusinessDto; branches: PublicBranchDto[] }>(
      `/api/v1/public/businesses/${businessSlug}/branches`,
    );
  },
  getBranch(businessSlug: string, branchPublicId: string) {
    return apiGet<PublicBranchShowResponse>(
      `/api/v1/public/businesses/${businessSlug}/branches/${branchPublicId}`,
    );
  },
  getBranchMenu(businessSlug: string, branchPublicId: string) {
    return apiGet<PublicBranchMenuResponse>(
      `/api/v1/public/businesses/${businessSlug}/branches/${branchPublicId}/menu`,
    );
  },
};
