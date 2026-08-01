"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Badge, EmptyState, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Field, Input, Select } from "@/components/ui/forms";
import {
  restaurantInventoryApi,
  restaurantMenuAdminApi,
  type InventoryRow,
} from "@/features/restaurant/api/restaurant-admin-api";
import { useRestaurantShell } from "@/features/restaurant/hooks/use-restaurant-shell";
import { ApiError } from "@/lib/api/client";
import { useToast } from "@/components/ui/navigation";

export default function RestaurantInventoryPage() {
  const { brand, portalLabel, items: navItems, copy } = useRestaurantShell();
  const qc = useQueryClient();
  const toast = useToast();
  const [lowStockOnly, setLowStockOnly] = useState(false);
  const [adjustTarget, setAdjustTarget] = useState<InventoryRow | null>(null);
  const [delta, setDelta] = useState("0");
  const [setQty, setSetQty] = useState("");
  const [reason, setReason] = useState("");
  const [bootstrapItemId, setBootstrapItemId] = useState("");
  const [bootstrapQty, setBootstrapQty] = useState("0");
  const [bootstrapThreshold, setBootstrapThreshold] = useState("5");

  const inventory = useQuery({
    queryKey: ["restaurant", "inventory", lowStockOnly ? "low" : "all"],
    queryFn: async () =>
      (await restaurantInventoryApi.list({ low_stock: lowStockOnly })).data,
  });

  const menuItems = useQuery({
    queryKey: ["restaurant", "menu-items"],
    queryFn: async () => (await restaurantMenuAdminApi.listItems()).data.items,
    enabled: copy.inventoryMode === "counted",
  });

  const adjustMutation = useMutation({
    mutationFn: async () => {
      if (!adjustTarget) return;
      const body: Record<string, unknown> = {
        variant_public_id: adjustTarget.variant_public_id,
        reason: reason || null,
      };
      if (setQty !== "") {
        body.set_quantity = Number(setQty);
      } else {
        body.delta = Number(delta);
      }
      await restaurantInventoryApi.adjust(adjustTarget.menu_item_public_id, body);
    },
    onSuccess: () => {
      setAdjustTarget(null);
      setDelta("0");
      setSetQty("");
      setReason("");
      qc.invalidateQueries({ queryKey: ["restaurant", "inventory"] });
      toast.push({ title: "Stock updated", description: "Inventory quantity saved." });
    },
    onError: (err) => {
      const message = err instanceof ApiError ? err.message : "Could not adjust stock.";
      toast.push({ title: "Adjustment failed", description: message });
    },
  });

  const configureMutation = useMutation({
    mutationFn: async () => {
      if (!bootstrapItemId) throw new ApiError("Choose a product.", 422);
      await restaurantInventoryApi.configure(bootstrapItemId, {
        track_stock: true,
        quantity_on_hand: Number(bootstrapQty || 0),
        low_stock_threshold: Number(bootstrapThreshold || 5),
      });
    },
    onSuccess: () => {
      setBootstrapItemId("");
      setBootstrapQty("0");
      qc.invalidateQueries({ queryKey: ["restaurant", "inventory"] });
      toast.push({ title: "Inventory configured", description: "Stock tracking enabled for this product." });
    },
    onError: (err) => {
      const message = err instanceof ApiError ? err.message : "Could not configure inventory.";
      toast.push({ title: "Configure failed", description: message });
    },
  });

  const rows = inventory.data?.inventories ?? [];
  const trackedItemIds = useMemo(
    () => new Set(rows.filter((r) => !r.variant_public_id).map((r) => r.menu_item_public_id)),
    [rows],
  );
  const untrackedItems = (menuItems.data ?? []).filter((i) => !trackedItemIds.has(i.public_id));

  return (
    <AdminShell
      brand={brand}
      portalLabel={portalLabel}
      items={navItems}
      title="Inventory"
      subtitle={
        copy.inventoryMode === "counted"
          ? "Branch stock levels, low-stock alerts, and manual adjustments"
          : "Availability is managed with sold-out toggles on the catalogue"
      }
      actions={
        <div className="flex gap-2">
          <Link href="/restaurant/menu">
            <Button variant="outline" size="sm">
              {copy.catalogueLabel}
            </Button>
          </Link>
          {copy.inventoryMode === "counted" ? (
            <Button
              variant={lowStockOnly ? "primary" : "outline"}
              size="sm"
              onClick={() => setLowStockOnly((v) => !v)}
            >
              {lowStockOnly ? "Showing low stock" : "Low stock only"}
            </Button>
          ) : null}
        </div>
      }
    >
      {copy.inventoryMode !== "counted" ? (
        <EmptyState
          title="Boolean availability mode"
          description="This business type uses sold-out / available toggles instead of quantity tracking. Manage availability from the catalogue."
        />
      ) : (
        <div className="space-y-6">
          <section className="rounded-lg border bg-white p-5 space-y-4">
            <h2 className="text-lg font-semibold">Enable stock tracking</h2>
            <div className="grid gap-3 sm:grid-cols-[1fr_100px_100px_auto]">
              <Select
                value={bootstrapItemId}
                onChange={(e) => setBootstrapItemId(e.target.value)}
              >
                <option value="">Select product…</option>
                {untrackedItems.map((i) => (
                  <option key={i.public_id} value={i.public_id}>
                    {i.name}
                  </option>
                ))}
              </Select>
              <Field label="Qty" htmlFor="boot-qty">
                <Input
                  id="boot-qty"
                  type="number"
                  value={bootstrapQty}
                  onChange={(e) => setBootstrapQty(e.target.value)}
                />
              </Field>
              <Field label="Low at" htmlFor="boot-low">
                <Input
                  id="boot-low"
                  type="number"
                  value={bootstrapThreshold}
                  onChange={(e) => setBootstrapThreshold(e.target.value)}
                />
              </Field>
              <Button
                size="sm"
                disabled={!bootstrapItemId || configureMutation.isPending}
                onClick={() => configureMutation.mutate()}
              >
                Enable
              </Button>
            </div>
          </section>

          {inventory.isLoading ? <Skeleton className="h-40" /> : null}
          {!inventory.isLoading && rows.length === 0 ? (
            <EmptyState
              title="No inventory rows yet"
              description="Enable stock tracking on a product to start managing quantities."
            />
          ) : null}

          {rows.length > 0 ? (
            <div className="overflow-x-auto rounded-lg border bg-white">
              <table className="min-w-full text-sm">
                <thead className="bg-[var(--surface-muted)] text-left">
                  <tr>
                    <th className="px-4 py-3 font-medium">Product</th>
                    <th className="px-4 py-3 font-medium">Variant</th>
                    <th className="px-4 py-3 font-medium">On hand</th>
                    <th className="px-4 py-3 font-medium">Reserved</th>
                    <th className="px-4 py-3 font-medium">Available</th>
                    <th className="px-4 py-3 font-medium">Status</th>
                    <th className="px-4 py-3 font-medium" />
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.public_id} className="border-t">
                      <td className="px-4 py-3">{row.menu_item_name}</td>
                      <td className="px-4 py-3 text-[var(--text-muted)]">
                        {row.variant_name ?? "—"}
                      </td>
                      <td className="px-4 py-3 font-medium">{row.quantity_on_hand}</td>
                      <td className="px-4 py-3">{row.quantity_reserved ?? 0}</td>
                      <td className="px-4 py-3 font-medium">{row.quantity_available ?? row.quantity_on_hand}</td>
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap gap-1">
                          {!row.is_in_stock ? <Badge tone="error">Out of stock</Badge> : null}
                          {row.is_low_stock && row.is_in_stock ? (
                            <Badge tone="warning">Low stock</Badge>
                          ) : null}
                          {row.is_in_stock && !row.is_low_stock ? (
                            <Badge tone="success">In stock</Badge>
                          ) : null}
                        </div>
                      </td>
                      <td className="px-4 py-3 text-right">
                        <Button size="sm" variant="outline" onClick={() => setAdjustTarget(row)}>
                          Adjust
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null}

          {adjustTarget ? (
            <section className="rounded-lg border bg-white p-5 space-y-4">
              <h2 className="text-lg font-semibold">
                Adjust {adjustTarget.menu_item_name}
                {adjustTarget.variant_name ? ` · ${adjustTarget.variant_name}` : ""}
              </h2>
              <p className="text-sm text-[var(--text-muted)]">
                On hand: {adjustTarget.quantity_on_hand} · Reserved:{" "}
                {adjustTarget.quantity_reserved ?? 0} · Available:{" "}
                {adjustTarget.quantity_available ?? adjustTarget.quantity_on_hand}
              </p>
              <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Delta (+/−)" htmlFor="adj-delta">
                  <Input
                    id="adj-delta"
                    type="number"
                    value={delta}
                    disabled={setQty !== ""}
                    onChange={(e) => setDelta(e.target.value)}
                  />
                </Field>
                <Field label="Or set quantity" htmlFor="adj-set">
                  <Input
                    id="adj-set"
                    type="number"
                    value={setQty}
                    onChange={(e) => setSetQty(e.target.value)}
                  />
                </Field>
              </div>
              <Field label="Reason (optional)" htmlFor="adj-reason">
                <Input id="adj-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
              </Field>
              <div className="flex gap-2">
                <Button
                  size="sm"
                  disabled={adjustMutation.isPending}
                  onClick={() => adjustMutation.mutate()}
                >
                  Save adjustment
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setAdjustTarget(null)}>
                  Cancel
                </Button>
              </div>
            </section>
          ) : null}
        </div>
      )}
    </AdminShell>
  );
}
