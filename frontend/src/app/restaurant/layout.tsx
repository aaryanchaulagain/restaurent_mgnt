"use client";

import { RestaurantGuard } from "@/features/auth/guards/route-guard";
import { RestaurantContextBanner } from "@/features/restaurant/components/restaurant-context-banner";
import { BranchSwitcher } from "@/features/business/components/branch-switcher";
import { ModulePermissionGate } from "@/features/restaurant/components/module-permission-gate";

export default function RestaurantLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <RestaurantGuard>
      <RestaurantContextBanner />
      <div className="border-b border-[var(--border-subtle)] bg-[var(--surface-elevated)] px-4 py-3 sm:px-6">
        <BranchSwitcher />
      </div>
      <ModulePermissionGate>{children}</ModulePermissionGate>
    </RestaurantGuard>
  );
}
