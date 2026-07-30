"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Badge, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Field, Input, Checkbox } from "@/components/ui/forms";
import { ConfirmDialog, Modal } from "@/components/ui/overlay";
import { restaurantMenuAdminApi } from "@/features/restaurant/api/restaurant-admin-api";
import { useRestaurantProfile } from "@/features/restaurant/hooks/use-restaurant-profile";
import { restaurantNav } from "@/lib/admin-nav";
import { ApiError } from "@/lib/api/client";

type Category = { public_id: string; name: string; is_active: boolean; sort_order: number };

export default function MenuCategoriesPage() {
  const profile = useRestaurantProfile();
  const qc = useQueryClient();
  const [createOpen, setCreateOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Category | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Category | null>(null);
  const [formName, setFormName] = useState("");
  const [formActive, setFormActive] = useState(true);
  const [formError, setFormError] = useState("");

  const categories = useQuery({
    queryKey: ["restaurant", "categories"],
    queryFn: async () => (await restaurantMenuAdminApi.listCategories()).data.categories as Category[],
  });

  const createMutation = useMutation({
    mutationFn: () => restaurantMenuAdminApi.createCategory({ name: formName, is_active: formActive }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ["restaurant", "categories"] }); setCreateOpen(false); setFormName(""); setFormError(""); },
    onError: (e) => setFormError(e instanceof ApiError ? Object.values(e.errors ?? {}).flat().join(", ") : "Failed"),
  });

  const updateMutation = useMutation({
    mutationFn: () => restaurantMenuAdminApi.updateCategory(editTarget!.public_id, { name: formName, is_active: formActive }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ["restaurant", "categories"] }); setEditTarget(null); setFormError(""); },
    onError: (e) => setFormError(e instanceof ApiError ? Object.values(e.errors ?? {}).flat().join(", ") : "Failed"),
  });

  const deleteMutation = useMutation({
    mutationFn: () => restaurantMenuAdminApi.deleteCategory(deleteTarget!.public_id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ["restaurant", "categories"] }); setDeleteTarget(null); },
  });

  const reorderMutation = useMutation({
    mutationFn: (order: string[]) => restaurantMenuAdminApi.reorderCategories(order),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["restaurant", "categories"] }),
  });

  const move = (idx: number, dir: -1 | 1) => {
    const list = [...(categories.data ?? [])];
    const target = idx + dir;
    if (target < 0 || target >= list.length) return;
    [list[idx], list[target]] = [list[target], list[idx]];
    reorderMutation.mutate(list.map((c) => c.public_id));
  };

  const openEdit = (cat: Category) => { setEditTarget(cat); setFormName(cat.name); setFormActive(cat.is_active); setFormError(""); };
  const openCreate = () => { setCreateOpen(true); setFormName(""); setFormActive(true); setFormError(""); };

  return (
    <AdminShell
      brand={profile.data?.trading_name ?? "Restaurant"}
      portalLabel="Restaurant Admin"
      items={restaurantNav}
      title="Menu categories"
      subtitle="Create, reorder and manage categories"
      actions={<Button size="sm" onClick={openCreate}>Add category</Button>}
    >
      {categories.isLoading ? <Skeleton className="h-40 w-full" /> : (
        <ul className="space-y-2">
          {(categories.data ?? []).map((c, idx) => (
            <li key={c.public_id} className="flex items-center justify-between gap-3 rounded-lg border p-3">
              <div className="flex items-center gap-3">
                <div className="flex flex-col gap-1">
                  <Button size="sm" variant="ghost" disabled={idx === 0} onClick={() => move(idx, -1)}>↑</Button>
                  <Button size="sm" variant="ghost" disabled={idx === (categories.data?.length ?? 0) - 1} onClick={() => move(idx, 1)}>↓</Button>
                </div>
                <div>
                  <p className="font-medium">{c.name}</p>
                  <Badge tone={c.is_active ? "success" : "error"}>{c.is_active ? "Active" : "Inactive"}</Badge>
                </div>
              </div>
              <div className="flex gap-2">
                <Button size="sm" variant="outline" onClick={() => openEdit(c)}>Edit</Button>
                <Button size="sm" variant="destructive" onClick={() => setDeleteTarget(c)}>Archive</Button>
              </div>
            </li>
          ))}
        </ul>
      )}

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Add category">
        <div className="space-y-4">
          <Field label="Category name" htmlFor="cat-name"><Input id="cat-name" value={formName} onChange={(e) => setFormName(e.target.value)} /></Field>
          <Checkbox label="Active" checked={formActive} onChange={(e) => setFormActive(e.target.checked)} />
          {formError ? <p className="text-sm text-red-600">{formError}</p> : null}
          <Button onClick={() => createMutation.mutate()} loading={createMutation.isPending}>Create</Button>
        </div>
      </Modal>

      <Modal open={Boolean(editTarget)} onClose={() => setEditTarget(null)} title="Edit category">
        <div className="space-y-4">
          <Field label="Category name" htmlFor="cat-edit-name"><Input id="cat-edit-name" value={formName} onChange={(e) => setFormName(e.target.value)} /></Field>
          <Checkbox label="Active" checked={formActive} onChange={(e) => setFormActive(e.target.checked)} />
          {formError ? <p className="text-sm text-red-600">{formError}</p> : null}
          <Button onClick={() => updateMutation.mutate()} loading={updateMutation.isPending}>Save</Button>
        </div>
      </Modal>

      <ConfirmDialog
        open={Boolean(deleteTarget)}
        onClose={() => setDeleteTarget(null)}
        title={`Archive "${deleteTarget?.name}"?`}
        description="Items in this category will remain but be unassigned."
        confirmLabel="Archive"
        destructive
        onConfirm={() => deleteMutation.mutate()}
      />
    </AdminShell>
  );
}
