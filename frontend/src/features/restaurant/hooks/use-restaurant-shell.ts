"use client";

import { useMemo } from "react";
import {
  getBusinessTypeConfig,
  type BusinessTypeConfig,
} from "@/features/business/config/business-type-config";
import { useRestaurantProfile } from "@/features/restaurant/hooks/use-restaurant-profile";
import { restaurantNavFor } from "@/lib/admin-nav";

/**
 * Shared shell props for restaurant-portal pages, driven by profile.business_type.
 */
export function useRestaurantShell(): {
  profile: ReturnType<typeof useRestaurantProfile>;
  brand: string;
  portalLabel: string;
  items: ReturnType<typeof restaurantNavFor>;
  copy: BusinessTypeConfig;
  businessType: string;
} {
  const profile = useRestaurantProfile();
  const businessType = profile.data?.business_type ?? profile.data?.vendor_type ?? null;
  const copy = useMemo(() => getBusinessTypeConfig(businessType), [businessType]);
  const items = useMemo(() => restaurantNavFor(businessType), [businessType]);

  return {
    profile,
    brand: profile.data?.trading_name ?? copy.label,
    portalLabel: copy.portalLabel,
    items,
    copy,
    businessType: copy.type,
  };
}
