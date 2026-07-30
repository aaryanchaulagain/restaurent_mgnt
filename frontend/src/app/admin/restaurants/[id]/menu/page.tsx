"use client";

import Link from "next/link";
import { use, useLayoutEffect, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Badge, EmptyState, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Checkbox, SearchInput, Select } from "@/components/ui/forms";
import { ConfirmDialog } from "@/components/ui/overlay";
import { adminNav } from "@/lib/admin-nav";
import { formatCents } from "@/lib/utils";
import { adminRestaurantApi } from "@/features/admin/api/admin-restaurant-api";
import {
  restaurantMenuAdminApi,
  type AdminMenuItem,
} from "@/features/restaurant/api/restaurant-admin-api";
import { setRestaurantContextPublicId } from "@/features/restaurant/lib/restaurant-context";
import { openRestaurantMenuEditor } from "@/features/admin/lib/open-restaurant-menu";

export default function AdminRestaurantMenuPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = use(params);
  const qc = useQueryClient();
  const [ready, setReady] = useState(false);
  const [query, setQuery] = useState("");
  const [filter, setFilter] = useState({ status: "all", availability: "all", featured: "all" });
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [bulkAction, setBulkAction] = useState("");
  const [confirmBulk, setConfirmBulk] = useState(false);

  useLayoutEffect(() => {
    setRestaurantContextPublicId(id);
    setReady(true);
  }, [id]);

  const restaurant = useQuery({
    queryKey: ["admin-restaurant", id],
    queryFn: async () => (await adminRestaurantApi.show(id)).data.restaurant,
  });

  const items = useQuery({
    queryKey: ["admin", "restaurant", id, "menu-items"],
    queryFn: async () => (await restaurantMenuAdminApi.listItems()).data.items,
    enabled: ready,
  });

  const bulkMutation = useMutation({
    mutationFn: async () => {
      await restaurantMenuAdminApi.bulk(bulkAction, [...selected]);
    },
    onSuccess: () => {
      setSelected(new Set());
      setBulkAction("");
      qc.invalidateQueries({ queryKey: ["admin", "restaurant", id, "menu-items"] });
    },
  });

  const dupMutation = useMutation({
    mutationFn: (itemId: string) => restaurantMenuAdminApi.duplicateItem(itemId),
    onSuccess: () =>
      qc.invalidateQueries({ queryKey: ["admin", "restaurant", id, "menu-items"] }),
  });

  const filtered = useMemo(() => {
    let list = items.data ?? [];
    if (query.trim()) {
      const q = query.toLowerCase();
      list = list.filter((i) => i.name.toLowerCase().includes(q));
    }
    if (filter.status === "active") list = list.filter((i) => i.is_active);
    if (filter.status === "inactive") list = list.filter((i) => !i.is_active);
    if (filter.availability === "available") list = list.filter((i) => i.is_available);
    if (filter.availability === "sold_out") list = list.filter((i) => !i.is_available);
    if (filter.featured === "featured") list = list.filter((i) => i.is_featured);
    return list;
  }, [items.data, query, filter]);

  const name = restaurant.data?.trading_name ?? "Restaurant";

  return (
    <AdminShell
      brand="Suvakamana"
      portalLabel="Super Admin"
      items={adminNav}
      title={`${name} · Menus`}
      subtitle="Add, edit and upload menu items for this restaurant"
      actions={
        <div className="flex flex-wrap gap-2">
          <Link href={`/admin/restaurants/${id}`}>
            <Button variant="outline" size="sm">
              Back
            </Button>
          </Link>
          {restaurant.data?.slug ? (
            <Link href={`/restaurants/${restaurant.data.slug}`} target="_blank">
              <Button variant="outline" size="sm">
                View on site
              </Button>
            </Link>
          ) : null}
          <Button
            size="sm"
            variant="outline"
            onClick={() => openRestaurantMenuEditor(id, "/restaurant/menu/categories")}
          >
            Categories
          </Button>
          <Button
            size="sm"
            onClick={() => openRestaurantMenuEditor(id, "/restaurant/menu/create")}
          >
            Add menu item
          </Button>
        </div>
      }
    >
      {!ready || items.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : items.isError ? (
        <EmptyState
          title="Could not load menu"
          description="Restaurant context may be missing. Retry or open the full menu editor."
          action={
            <Button onClick={() => openRestaurantMenuEditor(id, "/restaurant/menu")}>
              Open full menu editor
            </Button>
          }
        />
      ) : (
        <>
          <div className="mb-4 flex flex-wrap gap-3">
            <SearchInput
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Search items"
              className="w-full sm:max-w-xs"
            />
            <Select
              value={filter.status}
              onChange={(e) => setFilter((f) => ({ ...f, status: e.target.value }))}
              className="w-36"
            >
              <option value="all">All status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </Select>
            <Select
              value={filter.availability}
              onChange={(e) => setFilter((f) => ({ ...f, availability: e.target.value }))}
              className="w-40"
            >
              <option value="all">All availability</option>
              <option value="available">Available</option>
              <option value="sold_out">Sold out</option>
            </Select>
          </div>

          {selected.size > 0 ? (
            <div className="mb-4 flex flex-wrap items-center gap-3 rounded-lg border p-3">
              <span className="text-sm font-medium">{selected.size} selected</span>
              <Select
                value={bulkAction}
                onChange={(e) => setBulkAction(e.target.value)}
                className="w-44"
              >
                <option value="">Choose action…</option>
                <option value="sold_out">Mark sold out</option>
                <option value="available">Mark available</option>
                <option value="activate">Activate</option>
                <option value="deactivate">Deactivate</option>
                <option value="archive">Archive</option>
              </Select>
              <Button
                size="sm"
                disabled={!bulkAction || bulkMutation.isPending}
                onClick={() => {
                  if (["archive", "deactivate", "sold_out"].includes(bulkAction)) {
                    setConfirmBulk(true);
                  } else {
                    bulkMutation.mutate();
                  }
                }}
              >
                Apply
              </Button>
            </div>
          ) : null}

          {filtered.length === 0 ? (
            <EmptyState
              title="No menu items yet"
              description="Add dishes so customers can order from this restaurant on Suvakamana."
              action={
                <Button onClick={() => openRestaurantMenuEditor(id, "/restaurant/menu/create")}>
                  Add first item
                </Button>
              }
            />
          ) : (
            <div className="overflow-x-auto rounded-lg border bg-white">
              <table className="min-w-full text-left text-sm">
                <thead className="bg-[var(--surface-muted)] text-[var(--text-muted)]">
                  <tr>
                    <th className="px-3 py-2 w-8" />
                    <th className="px-3 py-2">Item</th>
                    <th className="px-3 py-2">Price</th>
                    <th className="px-3 py-2">Status</th>
                    <th className="px-3 py-2">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((item: AdminMenuItem) => (
                    <tr key={item.public_id} className="border-t">
                      <td className="px-3 py-2">
                        <Checkbox
                          label=""
                          checked={selected.has(item.public_id)}
                          onChange={() => {
                            setSelected((prev) => {
                              const next = new Set(prev);
                              if (next.has(item.public_id)) next.delete(item.public_id);
                              else next.add(item.public_id);
                              return next;
                            });
                          }}
                        />
                      </td>
                      <td className="px-3 py-2">
                        <button
                          type="button"
                          className="font-medium text-[var(--color-burnt-orange)] hover:underline"
                          onClick={() =>
                            openRestaurantMenuEditor(
                              id,
                              `/restaurant/menu/items/${item.public_id}`,
                            )
                          }
                        >
                          {item.name}
                        </button>
                        {item.short_description ? (
                          <p className="text-xs text-[var(--text-muted)]">
                            {item.short_description}
                          </p>
                        ) : null}
                      </td>
                      <td className="px-3 py-2">{formatCents(item.base_price_cents)}</td>
                      <td className="px-3 py-2 space-x-1">
                        <Badge tone={item.is_active ? "success" : "error"}>
                          {item.is_active ? "Active" : "Inactive"}
                        </Badge>
                        {!item.is_available ? <Badge tone="warning">Sold out</Badge> : null}
                      </td>
                      <td className="px-3 py-2">
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => dupMutation.mutate(item.public_id)}
                        >
                          Duplicate
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}

      <ConfirmDialog
        open={confirmBulk}
        onClose={() => setConfirmBulk(false)}
        title={`${bulkAction.replace("_", " ")} ${selected.size} items?`}
        description="This will update the selected menu items."
        confirmLabel="Confirm"
        destructive
        onConfirm={() => bulkMutation.mutate()}
      />
    </AdminShell>
  );
}
