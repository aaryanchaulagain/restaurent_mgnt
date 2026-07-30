"use client";

import { AdminShell } from "@/components/layout/admin-shell";
import { RestaurantHoursEditor } from "@/features/restaurant/components/hours-editor";
import { useRestaurantProfile } from "@/features/restaurant/hooks/use-restaurant-profile";
import { restaurantNav } from "@/lib/admin-nav";

export default function RestaurantHoursSettingsPage() {
  const profile = useRestaurantProfile();

  return (
    <AdminShell
      brand={profile.data?.trading_name ?? "Restaurant"}
      portalLabel="Restaurant Admin"
      items={restaurantNav}
      title="Opening hours"
      subtitle="Regular hours, split shifts, pickup and delivery schedules"
    >
      <RestaurantHoursEditor />
    </AdminShell>
  );
}
