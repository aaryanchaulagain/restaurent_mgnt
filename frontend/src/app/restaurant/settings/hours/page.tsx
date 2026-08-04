"use client";

import { AdminShell } from "@/components/layout/admin-shell";
import { RestaurantHoursEditor } from "@/features/restaurant/components/hours-editor";
import { useRestaurantShell } from "@/features/restaurant/hooks/use-restaurant-shell";

export default function RestaurantHoursSettingsPage() {
  const { brand, portalLabel, items: navItems } = useRestaurantShell();

  return (
    <AdminShell
      brand={brand}
      portalLabel={portalLabel}
      items={navItems}
      title="Opening hours"
      subtitle="Regular hours, split shifts, pickup and delivery schedules"
    >
      <RestaurantHoursEditor />
    </AdminShell>
  );
}
