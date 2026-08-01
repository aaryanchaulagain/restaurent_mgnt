"use client";

import Link from "next/link";
import { AdminShell } from "@/components/layout/admin-shell";
import { Badge } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { MenuItemEditor } from "@/features/restaurant/components/menu-item-editor";
import { useRestaurantShell } from "@/features/restaurant/hooks/use-restaurant-shell";
import { useAuth } from "@/features/auth/hooks/use-auth";
import { isSuperAdmin } from "@/features/auth/utils/roles";
import { getRestaurantContextPublicId } from "@/features/restaurant/lib/restaurant-context";

export default function RestaurantMenuCreatePage() {
  const { profile, brand, portalLabel, items, copy } = useRestaurantShell();
  const { user } = useAuth();
  const contextId =
    typeof window !== "undefined" ? getRestaurantContextPublicId() : null;
  const slug = profile.data?.slug;
  const isPlatform = slug === "suvakamana-restaurant";

  return (
    <AdminShell
      brand={brand}
      portalLabel={portalLabel}
      items={items}
      title={copy.addProductLabel}
      subtitle={`Creating for ${brand}${slug ? ` · /restaurants/${slug}` : ""}`}
      actions={
        isSuperAdmin(user) ? (
          <Link href="/admin/menus">
            <Button size="sm" variant="outline">
              Switch restaurant
            </Button>
          </Link>
        ) : null
      }
    >
      <div
        className={`mb-6 rounded-lg border p-4 ${
          isPlatform
            ? "border-[var(--color-burnt-orange)] bg-[rgba(216,102,45,0.08)]"
            : "border-[var(--border-subtle)] bg-white"
        }`}
      >
        <div className="flex flex-wrap items-center gap-2">
          {isPlatform ? (
            <Badge tone="accent">Main Suvakamana website</Badge>
          ) : (
            <Badge tone="neutral">{`Partner ${copy.label.toLowerCase()}`}</Badge>
          )}
          <p className="text-sm">
            Saving to <strong>{brand}</strong>
            {slug ? (
              <>
                {" "}
                (
                <Link className="underline" href={`/restaurants/${slug}`} target="_blank">
                  /restaurants/{slug}
                </Link>
                )
              </>
            ) : null}
          </p>
        </div>
        {profile.isError ? (
          <p className="mt-2 text-sm text-red-700">
            Restaurant context is invalid or expired. Go to{" "}
            <Link href="/admin/menus" className="underline">
              Menus
            </Link>{" "}
            and select a partner again.
          </p>
        ) : null}
        {isSuperAdmin(user) && !isPlatform && !profile.isError ? (
          <p className="mt-2 text-sm text-[var(--text-secondary)]">
            This is not the main Suvakamana restaurant. To add homepage dishes, go to{" "}
            <Link href="/admin/menus" className="underline">
              Menus
            </Link>{" "}
            and click <strong>Add item to Suvakamana</strong>.
            {contextId ? null : " Restaurant context is missing — switch restaurant first."}
          </p>
        ) : null}
      </div>
      <MenuItemEditor restaurantKey={contextId ?? slug ?? "default"} />
    </AdminShell>
  );
}
