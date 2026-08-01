"use client";

import { AdminShell } from "@/components/layout/admin-shell";
import { StatCard } from "@/components/marketplace/cards";
import { EmptyState } from "@/components/ui/feedback";
import { useRestaurantProfile } from "@/features/restaurant/hooks/use-restaurant-profile";
import { restaurantNav } from "@/lib/admin-nav";
import { formatCents } from "@/lib/utils";

export default function RestaurantSettlementsPage() {
  const profile = useRestaurantProfile();

  return (
    <AdminShell
      brand={profile.data?.trading_name ?? "Restaurant"}
      portalLabel="Restaurant Admin"
      items={restaurantNav}
      title="Settlements"
      subtitle="Weekly settlement statements and payout status"
    >
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <StatCard label="Pending payout" value={formatCents(0)} />
        <StatCard label="Commission held" value={formatCents(0)} />
        <StatCard label="Statements" value="0" />
      </div>
      <EmptyState
        title="No settlements yet"
        description="Settlement statements for this branch will appear here once payouts begin."
      />
    </AdminShell>
  );
}
