"use client";

import { useMemo } from "react";
import {
  getBusinessTypeConfig,
  type BusinessTypeConfig,
} from "@/features/business/config/business-type-config";
import { useRestaurantProfile } from "@/features/restaurant/hooks/use-restaurant-profile";
import { useBranchAuthorization } from "@/features/restaurant/hooks/use-branch-authorization";
import { restaurantNavFor } from "@/lib/admin-nav";

/**
 * Shared shell props for restaurant-portal pages, driven by profile.business_type
 * and effective branch permissions.
 */
export function useRestaurantShell(): {
  profile: ReturnType<typeof useRestaurantProfile>;
  brand: string;
  portalLabel: string;
  items: ReturnType<typeof restaurantNavFor>;
  copy: BusinessTypeConfig;
  businessType: string;
  permissions: string[] | null;
  role: string | null;
} {
  const profile = useRestaurantProfile();
  const authz = useBranchAuthorization();
  const businessType = profile.data?.business_type ?? profile.data?.vendor_type ?? null;
  const copy = useMemo(() => getBusinessTypeConfig(businessType), [businessType]);
  const permissions = authz.data?.permissions ?? null;
  const items = useMemo(
    () => restaurantNavFor(businessType, permissions),
    [businessType, permissions],
  );

  return {
    profile,
    brand: profile.data?.trading_name ?? copy.label,
    portalLabel: copy.portalLabel,
    items,
    copy,
    businessType: copy.type,
    permissions,
    role: authz.data?.role ?? null,
  };
}
