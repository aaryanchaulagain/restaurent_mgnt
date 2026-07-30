"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { useAuth } from "@/features/auth/hooks/use-auth";
import { isSuperAdmin } from "@/features/auth/utils/roles";
import {
  clearRestaurantContext,
  getRestaurantContextPublicId,
} from "@/features/restaurant/lib/restaurant-context";
import { adminRestaurantApi } from "@/features/admin/api/admin-restaurant-api";
import { Button } from "@/components/ui/button";

export function RestaurantContextBanner() {
  const { user } = useAuth();
  const publicId = typeof window !== "undefined" ? getRestaurantContextPublicId() : null;

  const restaurant = useQuery({
    queryKey: ["admin-restaurant-context", publicId],
    queryFn: async () => (await adminRestaurantApi.show(publicId!)).data.restaurant,
    enabled: Boolean(user && isSuperAdmin(user) && publicId),
  });

  if (!user || !isSuperAdmin(user) || !publicId) return null;

  return (
    <div className="border-b border-[var(--color-burnt-orange)]/30 bg-[rgba(216,102,45,0.08)] px-4 py-2 text-sm sm:px-6">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p>
          Managing as platform admin:{" "}
          <strong>{restaurant.data?.trading_name ?? "…"}</strong>
        </p>
        <div className="flex gap-2">
          <Link href={`/admin/restaurants/${publicId}`}>
            <Button size="sm" variant="outline">
              Admin restaurant
            </Button>
          </Link>
          <Link href="/admin/restaurants">
            <Button size="sm" variant="outline">
              Switch restaurant
            </Button>
          </Link>
          <Button
            size="sm"
            variant="ghost"
            onClick={() => {
              clearRestaurantContext();
              window.location.href = "/admin/restaurants";
            }}
          >
            Exit
          </Button>
        </div>
      </div>
    </div>
  );
}
