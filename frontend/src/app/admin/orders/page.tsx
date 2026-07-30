"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { EmptyState, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Field, Input, Select } from "@/components/ui/forms";
import { adminNav } from "@/lib/admin-nav";
import { formatCents } from "@/lib/utils";
import { adminOrderApi, ownershipLabel } from "@/features/orders/api/admin-order-api";
import { OrderStatusBadge } from "@/features/orders/components/order-status-badge";

export default function AdminOrdersPage() {
  const [status, setStatus] = useState("");
  const [orderNumber, setOrderNumber] = useState("");
  const [ownership, setOwnership] = useState("");
  const [page, setPage] = useState(1);

  const params = useMemo(
    () => ({
      status: status || undefined,
      order_number: orderNumber || undefined,
      ownership_type: ownership || undefined,
      page,
    }),
    [status, orderNumber, ownership, page],
  );

  const orders = useQuery({
    queryKey: ["admin-orders", params],
    queryFn: async () => (await adminOrderApi.list(params)).data,
  });

  const rows = orders.data?.orders ?? [];

  return (
    <AdminShell brand="Suvakamana" portalLabel="Super Admin" items={adminNav} title="Platform orders" subtitle="Operational visibility across all restaurants">
      <div className="mb-6 grid gap-4 rounded-lg border bg-white p-4 sm:grid-cols-3">
        <Field label="Order number"><Input value={orderNumber} onChange={(e) => { setOrderNumber(e.target.value); setPage(1); }} placeholder="SVK-" /></Field>
        <Field label="Status">
          <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
            <option value="">All</option>
            <option value="awaiting_restaurant">Awaiting</option>
            <option value="accepted">Accepted</option>
            <option value="preparing">Preparing</option>
            <option value="completed_pickup">Completed</option>
            <option value="cancelled">Cancelled</option>
            <option value="expired">Expired</option>
          </Select>
        </Field>
        <Field label="Ownership">
          <Select value={ownership} onChange={(e) => { setOwnership(e.target.value); setPage(1); }}>
            <option value="">All</option>
            <option value="first_party">Suvakamana-owned</option>
            <option value="third_party">Partner restaurant</option>
          </Select>
        </Field>
      </div>

      {orders.isLoading ? <Skeleton className="h-64 w-full" /> : rows.length === 0 ? (
        <EmptyState title="No orders" description="Adjust filters or wait for new orders." />
      ) : (
        <div className="overflow-x-auto rounded-lg border bg-white">
          <table className="min-w-full text-sm">
            <thead className="bg-[var(--surface-muted)] text-left">
              <tr>
                <th className="p-3">Order</th>
                <th className="p-3">Restaurant</th>
                <th className="p-3">Customer</th>
                <th className="p-3">Status</th>
                <th className="p-3">Payment</th>
                <th className="p-3">Total</th>
                <th className="p-3" />
              </tr>
            </thead>
            <tbody>
              {rows.map((o) => (
                <tr key={o.public_id} className="border-t">
                  <td className="p-3 font-medium">{o.order_number}</td>
                  <td className="p-3">
                    <p>{o.restaurant?.trading_name}</p>
                    <p className="text-xs text-[var(--text-muted)]">{ownershipLabel(o.restaurant?.ownership_type)}</p>
                  </td>
                  <td className="p-3">{o.customer_name ?? "Guest"}</td>
                  <td className="p-3"><OrderStatusBadge status={o.status} /></td>
                  <td className="p-3">{o.payment_method} · {o.payment_status}</td>
                  <td className="p-3">{formatCents(o.total_cents)}</td>
                  <td className="p-3"><Link href={`/admin/orders/${o.public_id}`}><Button size="sm" variant="outline">View</Button></Link></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </AdminShell>
  );
}
