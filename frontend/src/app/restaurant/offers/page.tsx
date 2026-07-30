"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Badge, EmptyState, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Checkbox, Field, Input, Select, Textarea } from "@/components/ui/forms";
import { ConfirmDialog, Modal } from "@/components/ui/overlay";
import {
  restaurantMenuAdminApi,
  restaurantOffersApi,
} from "@/features/restaurant/api/restaurant-admin-api";
import { useRestaurantProfile } from "@/features/restaurant/hooks/use-restaurant-profile";
import { restaurantNav } from "@/lib/admin-nav";
import { ApiError } from "@/lib/api/client";
import { formatCents } from "@/lib/utils";

type Offer = {
  public_id: string;
  name: string;
  description?: string | null;
  offer_type: string;
  value: number | string;
  minimum_order_cents?: number | null;
  maximum_discount_cents?: number | null;
  starts_at?: string | null;
  ends_at?: string | null;
  is_active: boolean;
  targets?: Array<{ target_type: string; target_id: number }>;
};

type TargetInput = { target_type: string; target_id: number; label?: string };

export default function RestaurantOffersPage() {
  const profile = useRestaurantProfile();
  const qc = useQueryClient();
  const [createOpen, setCreateOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Offer | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Offer | null>(null);

  const [name, setName] = useState("");
  const [desc, setDesc] = useState("");
  const [offerType, setOfferType] = useState("percentage");
  const [value, setValue] = useState("");
  const [minOrder, setMinOrder] = useState("");
  const [maxDiscount, setMaxDiscount] = useState("");
  const [startsAt, setStartsAt] = useState("");
  const [endsAt, setEndsAt] = useState("");
  const [isActive, setIsActive] = useState(true);
  const [targets, setTargets] = useState<TargetInput[]>([]);
  const [targetType, setTargetType] = useState("restaurant");
  const [targetId, setTargetId] = useState("");
  const [formError, setFormError] = useState("");

  const offers = useQuery({
    queryKey: ["restaurant", "offers"],
    queryFn: async () => (await restaurantOffersApi.list()).data.offers as Offer[],
  });

  const categories = useQuery({
    queryKey: ["restaurant", "categories"],
    queryFn: async () => (await restaurantMenuAdminApi.listCategories()).data.categories,
  });

  const items = useQuery({
    queryKey: ["restaurant", "menu-items"],
    queryFn: async () => (await restaurantMenuAdminApi.listItems()).data.items,
  });

  const resetForm = () => {
    setName(""); setDesc(""); setOfferType("percentage"); setValue(""); setMinOrder(""); setMaxDiscount("");
    setStartsAt(""); setEndsAt(""); setIsActive(true); setTargets([]); setFormError("");
  };

  const loadEdit = (o: Offer) => {
    setEditTarget(o);
    setName(o.name);
    setDesc(o.description ?? "");
    setOfferType(o.offer_type);
    setValue(String(o.value));
    setMinOrder(o.minimum_order_cents != null ? String(o.minimum_order_cents) : "");
    setMaxDiscount(o.maximum_discount_cents != null ? String(o.maximum_discount_cents) : "");
    setStartsAt(o.starts_at?.slice(0, 16) ?? "");
    setEndsAt(o.ends_at?.slice(0, 16) ?? "");
    setIsActive(o.is_active);
    setTargets(o.targets?.map((t) => ({ ...t, label: `${t.target_type}:${t.target_id}` })) ?? []);
    setFormError("");
  };

  const buildBody = () => ({
    name, description: desc || null, offer_type: offerType, value: Number(value),
    minimum_order_cents: minOrder ? Number(minOrder) : null,
    maximum_discount_cents: maxDiscount ? Number(maxDiscount) : null,
    starts_at: startsAt || null, ends_at: endsAt || null, is_active: isActive,
    targets: targets.map(({ target_type, target_id }) => ({ target_type, target_id })),
  });

  const createMutation = useMutation({
    mutationFn: () => restaurantOffersApi.create(buildBody()),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ["restaurant", "offers"] }); setCreateOpen(false); resetForm(); },
    onError: (e) => setFormError(e instanceof ApiError ? Object.values(e.errors ?? {}).flat().join(", ") : "Failed"),
  });

  const updateMutation = useMutation({
    mutationFn: () => restaurantOffersApi.update(editTarget!.public_id, buildBody()),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ["restaurant", "offers"] }); setEditTarget(null); resetForm(); },
    onError: (e) => setFormError(e instanceof ApiError ? Object.values(e.errors ?? {}).flat().join(", ") : "Failed"),
  });

  const deleteMutation = useMutation({
    mutationFn: () => restaurantOffersApi.remove(deleteTarget!.public_id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ["restaurant", "offers"] }); setDeleteTarget(null); },
  });

  const addTarget = () => {
    if (!targetId && targetType !== "restaurant") return;
    const id = targetType === "restaurant" ? (profile.data as unknown as { id: number })?.id ?? 0 : Number(targetId);
    setTargets((t) => [...t, { target_type: targetType, target_id: id, label: `${targetType}:${id}` }]);
    setTargetId("");
  };

  const removeTarget = (idx: number) => setTargets((t) => t.filter((_, i) => i !== idx));

  const offerForm = (
    <div className="space-y-4">
      <Field label="Offer name" htmlFor="offer-name"><Input id="offer-name" value={name} onChange={(e) => setName(e.target.value)} /></Field>
      <Field label="Description" htmlFor="offer-desc"><Textarea id="offer-desc" value={desc} onChange={(e) => setDesc(e.target.value)} /></Field>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Offer type" htmlFor="offer-type">
          <Select id="offer-type" value={offerType} onChange={(e) => setOfferType(e.target.value)}>
            <option value="percentage">Percentage</option>
            <option value="fixed_amount">Fixed amount</option>
            <option value="free_delivery">Free delivery</option>
          </Select>
        </Field>
        <Field label={offerType === "percentage" ? "Percentage" : "Amount (cents)"} htmlFor="offer-value">
          <Input id="offer-value" type="number" value={value} onChange={(e) => setValue(e.target.value)} />
        </Field>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Minimum order (cents)" htmlFor="offer-min"><Input id="offer-min" type="number" value={minOrder} onChange={(e) => setMinOrder(e.target.value)} /></Field>
        <Field label="Maximum discount (cents)" htmlFor="offer-max"><Input id="offer-max" type="number" value={maxDiscount} onChange={(e) => setMaxDiscount(e.target.value)} /></Field>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Starts at" htmlFor="offer-start"><Input id="offer-start" type="datetime-local" value={startsAt} onChange={(e) => setStartsAt(e.target.value)} /></Field>
        <Field label="Ends at" htmlFor="offer-end"><Input id="offer-end" type="datetime-local" value={endsAt} onChange={(e) => setEndsAt(e.target.value)} /></Field>
      </div>
      <Checkbox label="Active" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />

      <div className="rounded border p-3 space-y-3">
        <p className="text-sm font-medium">Offer targets</p>
        <div className="flex flex-wrap gap-2">
          <Select value={targetType} onChange={(e) => setTargetType(e.target.value)} className="w-36">
            <option value="restaurant">Restaurant</option>
            <option value="category">Category</option>
            <option value="item">Item</option>
          </Select>
          {targetType === "category" ? (
            <Select value={targetId} onChange={(e) => setTargetId(e.target.value)} className="w-48">
              <option value="">Select…</option>
              {(categories.data ?? []).map((c) => <option key={c.public_id} value={c.sort_order}>{c.name}</option>)}
            </Select>
          ) : targetType === "item" ? (
            <Select value={targetId} onChange={(e) => setTargetId(e.target.value)} className="w-48">
              <option value="">Select…</option>
              {(items.data ?? []).map((i) => <option key={i.public_id} value={i.public_id}>{i.name} ({formatCents(i.base_price_cents)})</option>)}
            </Select>
          ) : null}
          <Button size="sm" variant="outline" onClick={addTarget}>Add target</Button>
        </div>
        {targets.length > 0 ? (
          <ul className="space-y-1 text-sm">
            {targets.map((t, i) => (
              <li key={i} className="flex justify-between border-b pb-1">
                <span>{t.target_type} (ID: {t.target_id})</span>
                <Button size="sm" variant="ghost" onClick={() => removeTarget(i)}>Remove</Button>
              </li>
            ))}
          </ul>
        ) : <p className="text-xs text-[var(--text-muted)]">No targets — applies to entire restaurant by default.</p>}
      </div>

      {formError ? <p className="text-sm text-red-600">{formError}</p> : null}
    </div>
  );

  return (
    <AdminShell
      brand={profile.data?.trading_name ?? "Restaurant"}
      portalLabel="Restaurant Admin"
      items={restaurantNav}
      title="Offers"
      subtitle="Restaurant-funded promotions and discounts"
      actions={<Button size="sm" onClick={() => { setCreateOpen(true); resetForm(); }}>Create offer</Button>}
    >
      {offers.isLoading ? <Skeleton className="h-40 w-full" /> : (offers.data ?? []).length === 0 ? (
        <EmptyState title="No offers" description="Create your first promotion." />
      ) : (
        <div className="space-y-3">
          {(offers.data ?? []).map((o) => (
            <div key={o.public_id} className="flex items-center justify-between gap-4 rounded-lg border p-4">
              <div>
                <p className="font-semibold">{o.name}</p>
                <p className="text-sm text-[var(--text-muted)]">
                  {o.offer_type === "percentage" ? `${o.value}%` : formatCents(Number(o.value))} off
                  {o.minimum_order_cents ? ` · min ${formatCents(o.minimum_order_cents)}` : ""}
                </p>
                <div className="mt-1 flex gap-1">
                  <Badge tone={o.is_active ? "success" : "error"}>{o.is_active ? "Active" : "Inactive"}</Badge>
                  {o.targets?.length ? <Badge tone="info">{o.targets.length} target(s)</Badge> : null}
                </div>
              </div>
              <div className="flex gap-2">
                <Button size="sm" variant="outline" onClick={() => loadEdit(o)}>Edit</Button>
                <Button size="sm" variant="destructive" onClick={() => setDeleteTarget(o)}>Delete</Button>
              </div>
            </div>
          ))}
        </div>
      )}

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Create offer" className="sm:max-w-xl">
        {offerForm}
        <div className="mt-4"><Button onClick={() => createMutation.mutate()} loading={createMutation.isPending}>Create</Button></div>
      </Modal>

      <Modal open={Boolean(editTarget)} onClose={() => setEditTarget(null)} title="Edit offer" className="sm:max-w-xl">
        {offerForm}
        <div className="mt-4"><Button onClick={() => updateMutation.mutate()} loading={updateMutation.isPending}>Save</Button></div>
      </Modal>

      <ConfirmDialog
        open={Boolean(deleteTarget)}
        onClose={() => setDeleteTarget(null)}
        title={`Delete "${deleteTarget?.name}"?`}
        description="This offer will be permanently removed."
        confirmLabel="Delete"
        destructive
        onConfirm={() => deleteMutation.mutate()}
      />
    </AdminShell>
  );
}
