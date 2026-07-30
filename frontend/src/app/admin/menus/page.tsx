"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Badge, EmptyState, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Field, Input, Select } from "@/components/ui/forms";
import { adminNav } from "@/lib/admin-nav";
import { adminRestaurantApi } from "@/features/admin/api/admin-restaurant-api";
import { openRestaurantMenuEditor } from "@/features/admin/lib/open-restaurant-menu";

export default function AdminMenusPage() {
  const [ownership, setOwnership] = useState("");
  const [q, setQ] = useState("");

  const list = useQuery({
    queryKey: ["admin-menus-restaurants", ownership, q],
    queryFn: async () =>
      (
        await adminRestaurantApi.list({
          ownership_type: ownership || undefined,
          q: q || undefined,
          page: 1,
          per_page: 50,
        })
      ).data,
  });

  const rows = useMemo(() => {
    const all = (list.data?.restaurants ?? []).filter((r) =>
      ["active", "pending_setup", "temporarily_closed"].includes(r.status),
    );
    return [...all].sort((a, b) => {
      if (a.ownership_type === "first_party" && b.ownership_type !== "first_party") return -1;
      if (b.ownership_type === "first_party" && a.ownership_type !== "first_party") return 1;
      return a.trading_name.localeCompare(b.trading_name);
    });
  }, [list.data?.restaurants]);

  const platform = rows.find((r) => r.ownership_type === "first_party");

  return (
    <AdminShell
      brand="Suvakamana"
      portalLabel="Super Admin"
      items={adminNav}
      title="Menus"
      subtitle="Add menu items for the main Suvakamana website and partner restaurants"
    >
      {platform ? (
        <section className="mb-6 rounded-lg border-2 border-[var(--color-burnt-orange)] bg-[rgba(216,102,45,0.06)] p-5">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <Badge tone="accent">Main website</Badge>
              <h2 className="mt-2 text-2xl font-medium">{platform.trading_name}</h2>
              <p className="mt-1 text-sm text-[var(--text-secondary)]">
                Items added here appear on the homepage and at /restaurants/{platform.slug}
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button
                onClick={() => openRestaurantMenuEditor(platform.public_id, "/restaurant/menu")}
              >
                Manage menus
              </Button>
              <Button
                variant="outline"
                onClick={() =>
                  openRestaurantMenuEditor(platform.public_id, "/restaurant/menu/create")
                }
              >
                Add item to Suvakamana
              </Button>
              <Link href={`/restaurants/${platform.slug}`} target="_blank">
                <Button variant="ghost">View on site</Button>
              </Link>
            </div>
          </div>
        </section>
      ) : null}

      <div className="mb-6 grid gap-4 rounded-lg border bg-white p-4 sm:grid-cols-2">
        <Field label="Search restaurants">
          <Input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Name or slug"
          />
        </Field>
        <Field label="Ownership">
          <Select value={ownership} onChange={(e) => setOwnership(e.target.value)}>
            <option value="">All restaurants</option>
            <option value="first_party">Suvakamana-owned (main site)</option>
            <option value="third_party">Partner</option>
          </Select>
        </Field>
      </div>

      {list.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : rows.length === 0 ? (
        <EmptyState
          title="No restaurants"
          description="Provision a restaurant first, then manage its menu here."
          action={
            <Link href="/admin/restaurants/new">
              <Button>Add restaurant</Button>
            </Link>
          }
        />
      ) : (
        <div className="overflow-x-auto rounded-lg border bg-white">
          <table className="min-w-full text-sm">
            <thead className="bg-[var(--surface-muted)] text-left">
              <tr>
                <th className="p-3">Restaurant</th>
                <th className="p-3">Ownership</th>
                <th className="p-3">Status</th>
                <th className="p-3">Menus</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.public_id} className="border-t">
                  <td className="p-3">
                    <p className="font-medium">{r.trading_name}</p>
                    <p className="text-xs text-[var(--text-muted)]">{r.slug}</p>
                  </td>
                  <td className="p-3">
                    {r.ownership_type === "first_party" ? (
                      <Badge tone="accent">Suvakamana-owned</Badge>
                    ) : (
                      <Badge tone="neutral">Partner</Badge>
                    )}
                  </td>
                  <td className="p-3">{r.status.replaceAll("_", " ")}</td>
                  <td className="p-3">
                    <div className="flex flex-wrap gap-2">
                      <Button
                        size="sm"
                        onClick={() => openRestaurantMenuEditor(r.public_id, "/restaurant/menu")}
                      >
                        Manage menus
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                          openRestaurantMenuEditor(r.public_id, "/restaurant/menu/create")
                        }
                      >
                        Add item
                      </Button>
                      <Link href={`/restaurants/${r.slug}`} target="_blank">
                        <Button size="sm" variant="ghost">
                          View on site
                        </Button>
                      </Link>
                    </div>
                    {r.ownership_type === "first_party" ? (
                      <p className="mt-1 text-xs text-[var(--text-muted)]">
                        Shows on homepage “Order from Suvakamana”
                      </p>
                    ) : (
                      <p className="mt-1 text-xs text-[var(--text-muted)]">
                        Public page: /restaurants/{r.slug}
                      </p>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </AdminShell>
  );
}
