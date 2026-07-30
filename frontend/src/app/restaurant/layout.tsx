"use client";

import { RestaurantGuard } from "@/features/auth/guards/route-guard";
import { RestaurantContextBanner } from "@/features/restaurant/components/restaurant-context-banner";

export default function RestaurantLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <RestaurantGuard>
      <RestaurantContextBanner />
      {children}
    </RestaurantGuard>
  );
}
