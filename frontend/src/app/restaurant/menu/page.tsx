"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Badge, EmptyState, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Checkbox, SearchInput, Select } from "@/components/ui/forms";
import { ConfirmDialog } from "@/components/ui/overlay";
import {
  restaurantMenuAdminApi,
  type AdminMenuItem,
} from "@/features/restaurant/api/restaurant-admin-api";
import { useRestaurantProfile } from "@/features/restaurant/hooks/use-restaurant-profile";
import { restaurantNav } from "@/lib/admin-nav";
import { formatCents } from "@/lib/utils";

type Filter = { category: string; status: string; availability: string; dietary: string; featured: string };

export default function RestaurantMenuPage() {
  const profile = useRestaurantProfile();
  const qc = useQueryClient();
  const [query, setQuery] = useState("");
  const [filter, setFilter] = useState<Filter>({ category: "all", status: "all", availability: "all", dietary: "all", featured: "all" });
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [bulkAction, setBulkAction] = useState("");
  const [confirmBulk, setConfirmBulk] = useState(false);

  const items = useQuery({
    queryKey: ["restaurant", "menu-items"],
    queryFn: async () => (await restaurantMenuAdminApi.listItems()).data.items,
  });

  const bulkMutation = useMutation({
    mutationFn: async () => {
      await restaurantMenuAdminApi.bulk(bulkAction, [...selected]);
    },
    onSuccess: () => {
      setSelected(new Set());
      setBulkAction("");
      qc.invalidateQueries({ queryKey: ["restaurant", "menu-items"] });
    },
  });

  const dupMutation = useMutation({
    mutationFn: (id: string) => restaurantMenuAdminApi.duplicateItem(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["restaurant", "menu-items"] }),
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

  const toggleSelect = (id: string) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  };

  const destructiveBulkActions = ["archive", "deactivate", "sold_out"];

  const executeBulk = () => {
    if (destructiveBulkActions.includes(bulkAction)) {
      setConfirmBulk(true);
    } else {
      bulkMutation.mutate();
    }
  };

  return (
    <AdminShell
      brand={profile.data?.trading_name ?? "Restaurant"}
      portalLabel="Restaurant Admin"
      items={restaurantNav}
      title="Menu"
      subtitle="Categories, items, variants and availability"
      actions={
        <div className="flex gap-2">
          {profile.data?.slug ? (
            <Link href={`/restaurants/${profile.data.slug}`} target="_blank">
              <Button variant="outline" size="sm">
                View on site
              </Button>
            </Link>
          ) : null}
          <Link href="/restaurant/menu/categories"><Button variant="outline" size="sm">Categories</Button></Link>
          <Link href="/restaurant/menu/modifiers"><Button variant="outline" size="sm">Modifiers</Button></Link>
          <Link href="/restaurant/menu/create"><Button size="sm">Add item</Button></Link>
        </div>
      }
    >
      <div className="flex flex-wrap gap-3 mb-4">
        <SearchInput value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search items" className="w-full sm:max-w-xs" />
        <Select value={filter.status} onChange={(e) => setFilter((f) => ({ ...f, status: e.target.value }))} className="w-36">
          <option value="all">All status</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </Select>
        <Select value={filter.availability} onChange={(e) => setFilter((f) => ({ ...f, availability: e.target.value }))} className="w-40">
          <option value="all">All availability</option>
          <option value="available">Available</option>
          <option value="sold_out">Sold out</option>
        </Select>
        <Select value={filter.featured} onChange={(e) => setFilter((f) => ({ ...f, featured: e.target.value }))} className="w-36">
          <option value="all">All</option>
          <option value="featured">Featured</option>
        </Select>
      </div>

      {selected.size > 0 ? (
        <div className="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-[var(--color-burnt-orange)] bg-[rgba(216,102,45,0.06)] p-3">
          <span className="text-sm font-medium">{selected.size} selected</span>
          <Select value={bulkAction} onChange={(e) => setBulkAction(e.target.value)} className="w-44">
            <option value="">Choose action…</option>
            <option value="sold_out">Mark sold out</option>
            <option value="available">Mark available</option>
            <option value="activate">Activate</option>
            <option value="deactivate">Deactivate</option>
            <option value="archive">Archive</option>
            <option value="available_tomorrow">Available tomorrow</option>
          </Select>
          <Button size="sm" disabled={!bulkAction || bulkMutation.isPending} onClick={executeBulk}>
            {bulkMutation.isPending ? "Applying…" : "Apply"}
          </Button>
          <Button size="sm" variant="ghost" onClick={() => setSelected(new Set())}>Clear</Button>
        </div>
      ) : null}

      {items.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : items.isError ? (
        <EmptyState title="Could not load menu" description="Check your connection and try again." action={<Button onClick={() => items.refetch()}>Retry</Button>} />
      ) : filtered.length === 0 ? (
        <EmptyState title="No menu items" description="Create your first menu item to get started." />
      ) : (
        <div className="overflow-x-auto rounded-lg border border-[var(--border-subtle)]">
          <table className="min-w-full text-left text-sm">
            <thead className="bg-[var(--surface-muted)] text-[var(--text-muted)]">
              <tr>
                <th className="px-3 py-2 w-8"><span className="sr-only">Select</span></th>
                <th className="px-3 py-2">Item</th>
                <th className="px-3 py-2">Price</th>
                <th className="px-3 py-2">Status</th>
                <th className="px-3 py-2">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((item: AdminMenuItem) => (
                <tr key={item.public_id} className="border-t border-[var(--border-subtle)]">
                  <td className="px-3 py-2">
                    <Checkbox label="" checked={selected.has(item.public_id)} onChange={() => toggleSelect(item.public_id)} />
                  </td>
                  <td className="px-3 py-2">
                    <Link href={`/restaurant/menu/items/${item.public_id}`} className="font-medium text-[var(--color-burnt-orange)] hover:underline">
                      {item.name}
                    </Link>
                    {item.short_description ? <p className="text-xs text-[var(--text-muted)]">{item.short_description}</p> : null}
                  </td>
                  <td className="px-3 py-2">{formatCents(item.base_price_cents)}</td>
                  <td className="px-3 py-2 space-x-1">
                    <Badge tone={item.is_active ? "success" : "error"}>{item.is_active ? "Active" : "Inactive"}</Badge>
                    {!item.is_available ? <Badge tone="warning">Sold out</Badge> : null}
                    {item.is_featured ? <Badge tone="accent">Featured</Badge> : null}
                  </td>
                  <td className="px-3 py-2">
                    <Button size="sm" variant="ghost" onClick={() => dupMutation.mutate(item.public_id)}>Duplicate</Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <ConfirmDialog
        open={confirmBulk}
        onClose={() => setConfirmBulk(false)}
        title={`${bulkAction.replace("_", " ")} ${selected.size} items?`}
        description="This action will affect the selected items. This cannot be easily undone."
        confirmLabel="Confirm"
        destructive
        onConfirm={() => bulkMutation.mutate()}
      />
    </AdminShell>
  );
}
