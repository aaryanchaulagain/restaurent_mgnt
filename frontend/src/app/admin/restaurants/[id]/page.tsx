"use client";

import Link from "next/link";
import { use, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Badge, EmptyState, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Field, Input, Select } from "@/components/ui/forms";
import { useToast } from "@/components/ui/navigation";
import { adminNav } from "@/lib/admin-nav";
import { ApiError } from "@/lib/api/client";
import { adminRestaurantApi } from "@/features/admin/api/admin-restaurant-api";
import { openRestaurantMenuEditor } from "@/features/admin/lib/open-restaurant-menu";
import { setRestaurantContextPublicId } from "@/features/restaurant/lib/restaurant-context";
import { AdminBranchOversightPanel } from "@/features/reporting/components/admin-branch-oversight-panel";

export default function AdminRestaurantDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = use(params);
  const { push } = useToast();
  const qc = useQueryClient();
  const [ownerFirst, setOwnerFirst] = useState("");
  const [ownerLast, setOwnerLast] = useState("");
  const [ownerEmail, setOwnerEmail] = useState("");
  const [ownerRole, setOwnerRole] = useState<"restaurant_owner" | "restaurant_manager">(
    "restaurant_owner",
  );
  const [lastPassword, setLastPassword] = useState<string | null>(null);

  const detail = useQuery({
    queryKey: ["admin-restaurant", id],
    queryFn: async () => (await adminRestaurantApi.show(id)).data.restaurant,
  });

  const update = useMutation({
    mutationFn: (body: Record<string, unknown>) => adminRestaurantApi.update(id, body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin-restaurant", id] });
      push({ title: "Restaurant updated", tone: "success" });
    },
    onError: (err: unknown) => {
      push({
        title: "Update failed",
        description: err instanceof ApiError ? err.message : "Request failed",
        tone: "error",
      });
    },
  });

  const remove = useMutation({
    mutationFn: () => adminRestaurantApi.remove(id),
    onSuccess: () => {
      push({ title: "Restaurant removed", tone: "success" });
      window.location.href = "/admin/restaurants";
    },
    onError: (err: unknown) => {
      push({
        title: "Remove failed",
        description: err instanceof ApiError ? err.message : "Request failed",
        tone: "error",
      });
    },
  });

  const addOwner = useMutation({
    mutationFn: () =>
      adminRestaurantApi.addOwner(id, {
        first_name: ownerFirst,
        last_name: ownerLast,
        email: ownerEmail,
        role: ownerRole,
      }),
    onSuccess: (res) => {
      setLastPassword(res.data.temporary_password);
      setOwnerFirst("");
      setOwnerLast("");
      setOwnerEmail("");
      qc.invalidateQueries({ queryKey: ["admin-restaurant", id] });
      push({ title: "Owner assigned", tone: "success" });
    },
    onError: (err: unknown) => {
      push({
        title: "Could not add owner",
        description: err instanceof ApiError ? err.message : "Request failed",
        tone: "error",
      });
    },
  });

  const revokeOwner = useMutation({
    mutationFn: (userId: number) => adminRestaurantApi.removeOwner(id, userId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin-restaurant", id] });
      push({ title: "Access revoked", tone: "success" });
    },
    onError: (err: unknown) => {
      push({
        title: "Revoke failed",
        description: err instanceof ApiError ? err.message : "Request failed",
        tone: "error",
      });
    },
  });

  const restaurant = detail.data;

  function openPanel(path: string) {
    setRestaurantContextPublicId(id);
    window.location.href = path;
  }

  return (
    <AdminShell
      brand="Khana"
      portalLabel="Super Admin"
      items={adminNav}
      title={restaurant?.trading_name ?? "Restaurant"}
      subtitle={
        restaurant
          ? `${restaurant.slug} · ${restaurant.ownership_type === "first_party" ? "Khana-operated" : "Partner"}`
          : undefined
      }
      actions={
        <div className="flex flex-wrap gap-2">
          <Link href="/admin/restaurants">
            <Button variant="outline">Back</Button>
          </Link>
          {restaurant ? (
            <>
              <Link href={`/admin/restaurants/${id}/menu`}>
                <Button>Manage menus</Button>
              </Link>
              <Button
                variant="outline"
                onClick={() => openRestaurantMenuEditor(id, "/restaurant/menu/create")}
              >
                Add menu item
              </Button>
            </>
          ) : null}
        </div>
      }
    >
      {detail.isLoading || !restaurant ? (
        <Skeleton className="h-64 w-full" />
      ) : (
        <div className="space-y-6">
        {restaurant.business_public_id && restaurant.branch_public_id ? (
          <AdminBranchOversightPanel
            businessPublicId={restaurant.business_public_id}
            branchPublicId={restaurant.branch_public_id}
            restaurantName={restaurant.trading_name}
          />
        ) : null}
        <div className="grid gap-6 lg:grid-cols-2">
          <section className="rounded-lg border bg-white p-5">
            <div className="flex items-center justify-between gap-3">
              <h2 className="text-2xl">Overview</h2>
              <Badge
                tone={
                  restaurant.status === "active"
                    ? "success"
                    : restaurant.status === "suspended" || restaurant.status === "disabled"
                      ? "error"
                      : "warning"
                }
              >
                {restaurant.status.replaceAll("_", " ")}
              </Badge>
            </div>
            <dl className="mt-4 space-y-2 text-sm">
              <div className="flex justify-between gap-3">
                <dt className="text-[var(--text-muted)]">Legal name</dt>
                <dd className="text-right">{restaurant.legal_business_name}</dd>
              </div>
              <div className="flex justify-between gap-3">
                <dt className="text-[var(--text-muted)]">Email</dt>
                <dd className="text-right">{restaurant.business_email ?? "—"}</dd>
              </div>
              <div className="flex justify-between gap-3">
                <dt className="text-[var(--text-muted)]">Commission</dt>
                <dd>
                  {restaurant.commission_rate != null ? `${restaurant.commission_rate}%` : "—"}
                </dd>
              </div>
            </dl>
            <div className="mt-5 flex flex-wrap gap-2">
              <Button
                size="sm"
                variant="outline"
                disabled={update.isPending}
                onClick={() => update.mutate({ status: "active", accepting_orders: true })}
              >
                Activate
              </Button>
              <Button
                size="sm"
                variant="outline"
                disabled={update.isPending}
                onClick={() =>
                  update.mutate({
                    status: "temporarily_closed",
                    temporarily_closed_reason: "Closed by platform admin",
                  })
                }
              >
                Temp close
              </Button>
              <Button
                size="sm"
                variant="outline"
                disabled={update.isPending}
                onClick={() =>
                  update.mutate({
                    status: "suspended",
                    suspension_reason: "Suspended by platform admin",
                  })
                }
              >
                Suspend
              </Button>
              <Button
                size="sm"
                variant="destructive"
                disabled={remove.isPending}
                onClick={() => {
                  if (confirm("Remove this restaurant from the platform?")) remove.mutate();
                }}
              >
                Remove
              </Button>
            </div>
            <div className="mt-4 flex flex-wrap gap-2 border-t pt-4">
              <Link href={`/admin/restaurants/${id}/menu`}>
                <Button size="sm">Menus</Button>
              </Link>
              <Button
                size="sm"
                variant="outline"
                onClick={() => openRestaurantMenuEditor(id, "/restaurant/menu/create")}
              >
                Add item
              </Button>
              <Button size="sm" variant="ghost" onClick={() => openPanel("/restaurant/offers")}>
                Offers
              </Button>
              <Button size="sm" variant="ghost" onClick={() => openPanel("/restaurant/orders")}>
                Orders
              </Button>
              <Button
                size="sm"
                variant="ghost"
                onClick={() => openPanel("/restaurant/settings/payments")}
              >
                Payments
              </Button>
            </div>
          </section>

          <section className="space-y-4 rounded-lg border bg-white p-5">
            <h2 className="text-2xl">Owners &amp; managers</h2>
            {restaurant.owners.length === 0 ? (
              <EmptyState title="No operators" description="Add an owner so they can use the restaurant admin panel." />
            ) : (
              <ul className="space-y-3 text-sm">
                {restaurant.owners.map((m) => (
                  <li
                    key={m.user_id}
                    className="flex items-center justify-between gap-3 border-b border-[var(--border-subtle)] pb-3"
                  >
                    <div>
                      <p className="font-medium">{m.name}</p>
                      <p className="text-[var(--text-muted)]">
                        {m.email} · {(m.role ?? "").replaceAll("_", " ")}
                      </p>
                    </div>
                    <Button
                      size="sm"
                      variant="ghost"
                      disabled={revokeOwner.isPending}
                      onClick={() => revokeOwner.mutate(m.user_id)}
                    >
                      Revoke
                    </Button>
                  </li>
                ))}
              </ul>
            )}

            <form
              className="grid gap-3 border-t pt-4 sm:grid-cols-2"
              onSubmit={(e) => {
                e.preventDefault();
                addOwner.mutate();
              }}
            >
              <h3 className="sm:col-span-2 text-sm font-medium">Add owner / manager</h3>
              <Field label="First name">
                <Input required value={ownerFirst} onChange={(e) => setOwnerFirst(e.target.value)} />
              </Field>
              <Field label="Last name">
                <Input required value={ownerLast} onChange={(e) => setOwnerLast(e.target.value)} />
              </Field>
              <Field label="Email">
                <Input
                  type="email"
                  required
                  value={ownerEmail}
                  onChange={(e) => setOwnerEmail(e.target.value)}
                />
              </Field>
              <Field label="Role">
                <Select
                  value={ownerRole}
                  onChange={(e) =>
                    setOwnerRole(e.target.value as "restaurant_owner" | "restaurant_manager")
                  }
                >
                  <option value="restaurant_owner">Owner</option>
                  <option value="restaurant_manager">Manager</option>
                </Select>
              </Field>
              <div className="sm:col-span-2">
                <Button type="submit" size="sm" disabled={addOwner.isPending}>
                  {addOwner.isPending ? "Adding…" : "Add"}
                </Button>
              </div>
              {lastPassword ? (
                <p className="sm:col-span-2 text-sm text-[var(--text-secondary)]">
                  Temporary password: <strong>{lastPassword}</strong>
                </p>
              ) : null}
            </form>
          </section>
        </div>
        </div>
      )}
    </AdminShell>
  );
}
