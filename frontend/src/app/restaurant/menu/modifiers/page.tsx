"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Checkbox, Field, Input, Select } from "@/components/ui/forms";
import { Modal } from "@/components/ui/overlay";
import { restaurantMenuAdminApi } from "@/features/restaurant/api/restaurant-admin-api";
import { useRestaurantShell } from "@/features/restaurant/hooks/use-restaurant-shell";
import { ApiError } from "@/lib/api/client";
import { formatCents } from "@/lib/utils";

type ModOption = { public_id: string; name: string; price_adjustment_cents: number; is_default: boolean; is_active?: boolean };
type ModGroup = { public_id: string; name: string; selection_type: string; minimum_selections: number; maximum_selections: number; is_required: boolean; is_active?: boolean; options?: ModOption[] };

export default function ModifierGroupsPage() {
  const { brand, portalLabel, items, copy } = useRestaurantShell();
  const qc = useQueryClient();
  const [createOpen, setCreateOpen] = useState(false);
  const [optionTarget, setOptionTarget] = useState<ModGroup | null>(null);
  const [gName, setGName] = useState("");
  const [gType, setGType] = useState<"single" | "multiple">("single");
  const [gMin, setGMin] = useState("0");
  const [gMax, setGMax] = useState("1");
  const [gRequired, setGRequired] = useState(false);
  const [oName, setOName] = useState("");
  const [oPrice, setOPrice] = useState("0");
  const [oDefault, setODefault] = useState(false);
  const [formError, setFormError] = useState("");

  const groups = useQuery({
    queryKey: ["restaurant", "modifier-groups"],
    queryFn: async () => (await restaurantMenuAdminApi.listModifierGroups()).data.modifier_groups as ModGroup[],
  });

  const createGroupMutation = useMutation({
    mutationFn: () => restaurantMenuAdminApi.createModifierGroup({
      name: gName, selection_type: gType, minimum_selections: Number(gMin), maximum_selections: Number(gMax), is_required: gRequired,
    }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ["restaurant", "modifier-groups"] }); setCreateOpen(false); resetGroupForm(); setFormError(""); },
    onError: (e) => setFormError(e instanceof ApiError ? Object.values(e.errors ?? {}).flat().join(", ") : "Failed"),
  });

  const createOptionMutation = useMutation({
    mutationFn: () => restaurantMenuAdminApi.createModifierOption(optionTarget!.public_id, {
      name: oName, price_adjustment_cents: Number(oPrice), is_default: oDefault,
    }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ["restaurant", "modifier-groups"] }); setOName(""); setOPrice("0"); setODefault(false); setFormError(""); },
    onError: (e) => setFormError(e instanceof ApiError ? Object.values(e.errors ?? {}).flat().join(", ") : "Failed"),
  });

  const resetGroupForm = () => { setGName(""); setGType("single"); setGMin("0"); setGMax("1"); setGRequired(false); };

  if (!copy.supportsModifiers) {
    return (
      <AdminShell brand={brand} portalLabel={portalLabel} items={items} title="Modifiers">
        <p className="text-sm text-[var(--text-secondary)]">
          Modifier groups are not used for {copy.label.toLowerCase()} catalogues.
        </p>
      </AdminShell>
    );
  }

  return (
    <AdminShell
      brand={brand}
      portalLabel={portalLabel}
      items={items}
      title="Modifier groups"
      subtitle="Reusable modifier groups and options"
      actions={<Button size="sm" onClick={() => { setCreateOpen(true); resetGroupForm(); setFormError(""); }}>Add group</Button>}
    >
      {groups.isLoading ? <Skeleton className="h-40 w-full" /> : (
        <div className="space-y-4">
          {(groups.data ?? []).map((g) => (
            <section key={g.public_id} className="rounded-lg border bg-white p-4">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <p className="font-semibold">{g.name}</p>
                  <p className="text-xs text-[var(--text-muted)]">
                    {g.selection_type === "single" ? "Single select" : "Multi select"} · {g.is_required ? "Required" : "Optional"} · min {g.minimum_selections} max {g.maximum_selections}
                  </p>
                </div>
                <Button size="sm" variant="outline" onClick={() => { setOptionTarget(g); setFormError(""); }}>Add option</Button>
              </div>
              {g.options?.length ? (
                <ul className="mt-3 space-y-1">
                  {g.options.map((o) => (
                    <li key={o.public_id} className="flex items-center justify-between gap-2 rounded border px-3 py-1.5 text-sm">
                      <span>{o.name}{o.is_default ? " (default)" : ""}</span>
                      <span>{o.price_adjustment_cents ? `+${formatCents(o.price_adjustment_cents)}` : "—"}</span>
                    </li>
                  ))}
                </ul>
              ) : <p className="mt-2 text-sm text-[var(--text-muted)]">No options yet.</p>}
            </section>
          ))}
        </div>
      )}

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Add modifier group">
        <div className="space-y-4">
          <Field label="Group name" htmlFor="mg-name"><Input id="mg-name" value={gName} onChange={(e) => setGName(e.target.value)} /></Field>
          <Field label="Selection type" htmlFor="mg-type">
            <Select id="mg-type" value={gType} onChange={(e) => setGType(e.target.value as "single" | "multiple")}>
              <option value="single">Single</option>
              <option value="multiple">Multiple</option>
            </Select>
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Min selections" htmlFor="mg-min"><Input id="mg-min" type="number" value={gMin} onChange={(e) => setGMin(e.target.value)} /></Field>
            <Field label="Max selections" htmlFor="mg-max"><Input id="mg-max" type="number" value={gMax} onChange={(e) => setGMax(e.target.value)} /></Field>
          </div>
          <Checkbox label="Required" checked={gRequired} onChange={(e) => setGRequired(e.target.checked)} />
          {formError ? <p className="text-sm text-red-600">{formError}</p> : null}
          <Button onClick={() => createGroupMutation.mutate()} loading={createGroupMutation.isPending}>Create group</Button>
        </div>
      </Modal>

      <Modal open={Boolean(optionTarget)} onClose={() => setOptionTarget(null)} title={`Add option to "${optionTarget?.name}"`}>
        <div className="space-y-4">
          <Field label="Option name" htmlFor="mo-name"><Input id="mo-name" value={oName} onChange={(e) => setOName(e.target.value)} /></Field>
          <Field label="Price adjustment (cents)" htmlFor="mo-price"><Input id="mo-price" type="number" value={oPrice} onChange={(e) => setOPrice(e.target.value)} /></Field>
          <Checkbox label="Default option" checked={oDefault} onChange={(e) => setODefault(e.target.checked)} />
          {formError ? <p className="text-sm text-red-600">{formError}</p> : null}
          <Button onClick={() => createOptionMutation.mutate()} loading={createOptionMutation.isPending}>Add option</Button>
        </div>
      </Modal>
    </AdminShell>
  );
}
