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

function statusTone(status: string): "success" | "warning" | "error" | "neutral" {
  if (status === "active") return "success";
  if (status === "pending_setup" || status === "temporarily_closed") return "warning";
  if (status === "suspended" || status === "disabled") return "error";
  return "neutral";
}

export default function AdminRestaurantsPage() {
  const [status, setStatus] = useState("");
  const [ownership, setOwnership] = useState("");
  const [q, setQ] = useState("");
  const [page, setPage] = useState(1);

  const params = useMemo(
    () => ({
      status: status || undefined,
      ownership_type: ownership || undefined,
      q: q || undefined,
      page,
    }),
    [status, ownership, q, page],
  );

  const list = useQuery({
    queryKey: ["admin-restaurants", params],
    queryFn: async () => (await adminRestaurantApi.list(params)).data,
  });

  const rows = list.data?.restaurants ?? [];

  return (
    <AdminShell
      brand="Suvakamana"
      portalLabel="Super Admin"
      items={adminNav}
      title="Restaurants"
      subtitle="Provision restaurants, manage owners, and upload menus"
      actions={
        <Link href="/admin/restaurants/new">
          <Button>Add restaurant admin</Button>
        </Link>
      }
    >
      <div className="mb-6 grid gap-4 rounded-lg border bg-white p-4 sm:grid-cols-3">
        <Field label="Search">
          <Input
            value={q}
            onChange={(e) => {
              setQ(e.target.value);
              setPage(1);
            }}
            placeholder="Name, email, slug"
          />
        </Field>
        <Field label="Status">
          <Select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
          >
            <option value="">All</option>
            <option value="pending_setup">Pending setup</option>
            <option value="active">Active</option>
            <option value="temporarily_closed">Temporarily closed</option>
            <option value="suspended">Suspended</option>
            <option value="disabled">Disabled</option>
          </Select>
        </Field>
        <Field label="Ownership">
          <Select
            value={ownership}
            onChange={(e) => {
              setOwnership(e.target.value);
              setPage(1);
            }}
          >
            <option value="">All</option>
            <option value="first_party">Suvakamana-owned</option>
            <option value="third_party">Partner</option>
          </Select>
        </Field>
      </div>

      {list.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : rows.length === 0 ? (
        <EmptyState
          title="No restaurants"
          description="Provision a restaurant after a sales call to create their admin panel."
        />
      ) : (
        <div className="overflow-x-auto rounded-lg border bg-white">
          <table className="min-w-full text-sm">
            <thead className="bg-[var(--surface-muted)] text-left">
              <tr>
                <th className="p-3">Restaurant</th>
                <th className="p-3">Ownership</th>
                <th className="p-3">Commission</th>
                <th className="p-3">Staff</th>
                <th className="p-3">Status</th>
                <th className="p-3" />
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.public_id} className="border-t">
                  <td className="p-3">
                    <Link
                      href={`/admin/restaurants/${r.public_id}`}
                      className="font-medium hover:text-[var(--color-burnt-orange)]"
                    >
                      {r.trading_name}
                    </Link>
                    <p className="text-xs text-[var(--text-muted)]">{r.slug}</p>
                  </td>
                  <td className="p-3">
                    {r.ownership_type === "first_party" ? "Suvakamana" : "Partner"}
                  </td>
                  <td className="p-3">
                    {r.commission_rate != null ? `${r.commission_rate}%` : "—"}
                  </td>
                  <td className="p-3">{r.active_staff_count}</td>
                  <td className="p-3">
                    <Badge tone={statusTone(r.status)}>{r.status.replaceAll("_", " ")}</Badge>
                  </td>
                  <td className="p-3">
                    <div className="flex flex-wrap gap-2">
                      <Link href={`/admin/restaurants/${r.public_id}/menu`}>
                        <Button size="sm">Menus</Button>
                      </Link>
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                          openRestaurantMenuEditor(r.public_id, "/restaurant/menu/create")
                        }
                      >
                        Add item
                      </Button>
                      <Link href={`/admin/restaurants/${r.public_id}`}>
                        <Button size="sm" variant="ghost">
                          Manage
                        </Button>
                      </Link>
                    </div>
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
