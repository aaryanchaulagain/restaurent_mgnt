"use client";

import type { ReactNode } from "react";
import { AdminShell } from "@/components/layout/admin-shell";
import { useRestaurantShell } from "@/features/restaurant/hooks/use-restaurant-shell";

type Props = {
  title: string;
  subtitle?: string;
  children: ReactNode;
  brand?: string;
  portalLabel?: string;
};

/** AdminShell wired to permission-aware navigation for the partner portal. */
export function RestaurantPortalShell({
  title,
  subtitle,
  children,
  brand,
  portalLabel,
}: Props) {
  const shell = useRestaurantShell();

  return (
    <AdminShell
      brand={brand ?? shell.brand}
      portalLabel={portalLabel ?? shell.portalLabel}
      items={shell.items}
      title={title}
      subtitle={subtitle}
    >
      {children}
    </AdminShell>
  );
}
